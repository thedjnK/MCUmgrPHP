<?PHP
/******************************************************************************
** Copyright (C) 2024 Jamie M.
**
** Project: MCUmgrPHP
**
** Module: smp_transport_uart.php
**
** Notes:
**
** License: Licensed under the Apache License, Version 2.0 (the "License");
**          you may not use this file except in compliance with the License.
**          You may obtain a copy of the License at
**
**              http://www.apache.org/licenses/LICENSE-2.0
**
**          Unless required by applicable law or agreed to in writing,
**          software distributed under the License is distributed on an
**          "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND,
**          either express or implied. See the License for the specific
**          language governing permissions and limitations under the License.
**
*******************************************************************************/
namespace MCUmgr;

use MCUmgr\crc16;

use React\Promise\ExtendedPromiseInterface;
use React\Promise\Deferred;

use lepiaf\SerialPort\SerialPort;
use lepiaf\SerialPort\Parser\ParserInterface;
use lepiaf\SerialPort\Configure\TTYConfigure;

class uart_message_checker implements \lepiaf\SerialPort\Parser\ParserInterface
{
	private $message = NULL;
	private $receive_buffer = '';
	private $SMPBuffer = '';
	private $SMPBufferActualData = '';
	private $SMPWaitingForContinuation = false;
	private $waiting_packet_length = 0;

	public function getSeparator()
	{
	}

	public function parse(array $chars)
	{
	}

	public function setup()
	{
		$this->message = NULL;
		$this->receive_buffer = '';
		$this->SMPBuffer = '';
		$this->SMPBufferActualData = '';
		$this->SMPWaitingForContinuation = false;
		$this->waiting_packet_length = 0;
	}

	public function append($data)
	{
		$this->receive_buffer .= $data;
	}

	public function is_finished()
	{
		//Search for SMP packets
		$pos = strpos($this->receive_buffer, smp_transport_uart::SMP_FIRST_HEADER);
		$pos_other = strpos($this->receive_buffer, smp_transport_uart::SMP_CONTINUATION_HEADER);

		if ($pos !== false)
		{
			$posA = strpos($this->receive_buffer, "\x0a", $pos + 2);
		}
		else
		{
			$posA = false;
		}
		if ($pos_other !== false)
		{
			$posA_other = strpos($this->receive_buffer, "\x0a", $pos_other + 2);
		}
		else
		{
			$posA_other = false;
		}

		while (($pos !== false && $posA !== false) || ($pos_other !== false && $posA_other !== false))
		{
			if ($pos !== false && ($pos_other === false || $pos < $pos_other))
			{
				//Start
				//Check this header
				$this->SMPBuffer = base64_decode(substr($this->receive_buffer, ($pos + 2), ($posA - $pos - 2)));

				if (strlen($this->SMPBuffer) == 0)
				{
//log_error() << "Failed decoding base64";
				}
				else if (strlen($this->SMPBuffer) > 2)
				{
					//Check length
					$this->waiting_packet_length = ord($this->SMPBuffer[0]) << 8;
					$this->waiting_packet_length |= ord($this->SMPBuffer[1]) & 0xff;
					$this->SMPBuffer = substr($this->SMPBuffer, 2);

					if (strlen($this->SMPBuffer) >= $this->waiting_packet_length)
					{
						//We have a full packet, check the checksum
						$crc = crc16::crc16($this->SMPBuffer, 0, (strlen($this->SMPBuffer) - 2), 0x1021, 0, true);
						$message_crc = ord(($this->SMPBuffer[(strlen($this->SMPBuffer) - 2)])) << 8;
						$message_crc |= ord($this->SMPBuffer[(strlen($this->SMPBuffer) - 1)]) & 0xff;

						if ($crc == $message_crc)
						{
							//Good to parse message after removing CRC
							$this->SMPBuffer = substr($this->SMPBuffer, 0, (strlen($this->SMPBuffer) - 2));
							$this->message = new smp_message();
							$this->message->append($this->SMPBuffer);
							return true;
						}
						else
						{
							//CRC failure
//log_error() << "CRC failure, expected " << message_crc << " but got " << crc;
						}
					}
					else
					{
						//More data expected in another packet
						$this->SMPWaitingForContinuation = true;
						$this->SMPBufferActualData = $this->SMPBuffer;
					}
				}

				$this->receive_buffer = substr($this->receive_buffer, ($posA + 1));
				$pos = strpos($this->receive_buffer, smp_transport_uart::SMP_FIRST_HEADER);
				$pos_other = strpos($this->receive_buffer, smp_transport_uart::SMP_CONTINUATION_HEADER);

				if ($pos !== false)
				{
					$posA = strpos($this->receive_buffer, "\x0a", $pos + 2);
				}
				else
				{
					$posA = false;
				}
				if ($pos_other !== false)
				{
					$posA_other = strpos($this->receive_buffer, "\x0a", $pos_other + 2);
				}
				else
				{
					$posA_other = false;
				}
			}
			else if ($this->SMPWaitingForContinuation == true)
			{
				//Continuation
				//Check this header
				$this->SMPBuffer = base64_decode(substr($this->receive_buffer, ($pos_other + 2), ($posA_other - $pos_other - 2)));

				if (strlen($this->SMPBuffer) == 0)
				{
//log_error() << "Failed decoding base64";
				}
				else
				{
					//Check length
					$this->SMPBufferActualData .= $this->SMPBuffer;

					if (strlen($this->SMPBufferActualData) >= ($this->waiting_packet_length /*+ 2*/))
					{
						//We have a full packet, check the checksum
						$crc = crc16::crc16($this->SMPBufferActualData, 0, (strlen($this->SMPBufferActualData) - 2), 0x1021, 0, true);
						$message_crc = ord(($this->SMPBufferActualData[(strlen($this->SMPBufferActualData) - 2)])) << 8;
						$message_crc |= ord($this->SMPBufferActualData[(strlen($this->SMPBufferActualData) - 1)]) & 0xff;

						if ($crc == $message_crc)
						{
							//Good to parse message after removing CRC
							$this->SMPBufferActualData = substr($this->SMPBufferActualData, 0, (strlen($this->SMPBufferActualData) - 2));
							$this->message = new smp_message();
							$this->message->append($this->SMPBufferActualData);
							return true;
						}
						else
						{
//CRC failure
//log_error() << "CRC failure, expected " << message_crc << " but got " << crc;
						}

						$this->SMPBufferActualData = '';
						$this->SMPWaitingForContinuation = false;
					}
					else
					{
						//More data expected in another packet
						$this->SMPWaitingForContinuation = true;
					}
				}

				$this->receive_buffer = substr($this->receive_buffer, ($posA_other + 1));

				$pos = strpos($this->receive_buffer, smp_transport_uart::SMP_FIRST_HEADER);
				$pos_other = strpos($this->receive_buffer, smp_transport_uart::SMP_CONTINUATION_HEADER);

				if ($pos !== false)
				{
					$posA = strpos($this->receive_buffer, "\x0a", $pos + 2);
				}
				else
				{
					$posA = false;
				}
				if ($pos_other !== false)
				{
					$posA_other = strpos($this->receive_buffer, "\x0a", $pos_other + 2);
				}
				else
				{
					$posA_other = false;
				}
			}
		}

		if (strlen($this->receive_buffer) > 10 && strpos($this->receive_buffer, smp_transport_uart::SMP_FIRST_HEADER) === false && strpos($this->receive_buffer, smp_transport_uart::SMP_CONTINUATION_HEADER) === false)
		{
//log_error() << "Cleared garbage data in UART SMP transport buffer";
			$this->receive_buffer = '';
		}
		return false;
	}

	public function parsed()
	{
		return $this->message;
	}
}

class smp_transport_uart extends smp_transport
{
	const SMP_FIRST_HEADER = "\x06\x09";
	const SMP_CONTINUATION_HEADER = "\x04\x14";

//	public const UART_FLOW_CONTROL_NONE = 0;
//	public const UART_FLOW_CONTROL_SOFTWARE = 1;
//	public const UART_FLOW_CONTROL_HARDWARE = 2;

	private $config_port = '';
	private $config_baud = 0;
//	private $config_flow_control = false;
//	private $config_parity = '';
//	private $config_stop_bits = '';

	private $uart = NULL;
//	private $receive_message = NULL;
//	private $receive_enabled = false;

	public function set_connection_config($configuration): int
	{
		if ($this->is_connected() == true)
		{
			return smp_transport::SMP_TRANSPORT_ERROR_ALREADY_CONNECTED;
		}

		if (!isset($configuration['port']) || !isset($configuration['baud']))
		{
			return smp_transport::SMP_TRANSPORT_ERROR_INVALID_CONFIGURATION;
		}


		$this->config_port = $configuration['port'];
		$this->config_baud = $configuration['baud'];
		$this->config_set = true;

		return smp_transport::SMP_TRANSPORT_ERROR_OK;
	}

	public function connect(): int
	{
		$uart_configuration = new TTYConfigure();
		$uart_configuration->removeOption('9600');
		$uart_configuration->setOption($this->config_baud);
		$this->uart = new SerialPort(new uart_message_checker(), $uart_configuration);
		$this->uart->open($this->config_port);
		return smp_transport::SMP_TRANSPORT_ERROR_OK;
	}

	public function disconnect($force): int
	{
		if (is_null($this->uart))
		{
			return smp_transport::SMP_TRANSPORT_ERROR_NOT_CONNECTED;
		}

		$this->uart->close();
		$this->uart = NULL;
		return smp_transport::SMP_TRANSPORT_ERROR_OK;
	}

	public function is_connected(): bool
	{
		if (!is_null($this->uart))
		{
			try
			{
				$this->uart->ensureDeviceOpen();
				return 1;
			}
			catch (Exception $e)
			{
				$this->uart->close();
				$this->uart = NULL;
			}
		}

		return 0;
	}

	public function send(&$message): int
	{
		//127 bytes = 3 + base 64 message
		//base64 = 4 bytes output per 3 byte input
		$output = '';
		$size = $message->size();
		$size += 2;
		$output .= chr(($size & 0xff00) >> 8);
		$output .= chr($size & 0xff);
		$crc = crc16::crc16($message->data(), 0, $message->size(), 0x1021, 0, true);

		$inbase = self::SMP_FIRST_HEADER;
		$pos = 0;

		while ($pos < ($message->size() + 1))
		{
			/* Chunking required */
			$chunk_size = 93 - strlen($output);

			if (($chunk_size + $pos) > $message->size())
			{
				$chunk_size = $message->size() - $pos;

				if ($chunk_size == 0)
				{
					goto end;
				}
			}

			$output .= substr($message->data(), $pos, $chunk_size);
			$pos += $chunk_size;

			if ($pos == $message->size() && (93 - $chunk_size) > 2)
			{
end:
				$output .= chr(($crc & 0xff00) >> 8);
				$output .= chr($crc & 0xff);
				$pos += 2;
			}

			$inbase .= base64_encode($output);
			$inbase .= chr(0x0a);
			$this->uart->write($inbase);
			$inbase = self::SMP_CONTINUATION_HEADER;
			$output = '';
		}

		return smp_transport::SMP_TRANSPORT_ERROR_OK;
	}

	public function receive($max_wait_ms = 0): \React\Promise\Promise
	{
		//PHP after 14+ years still has an open bug report to support blocking with timeout for streams, it still does not work on windows, it still does not work on linux, therefore have a 250ms pause between read attempts to avoid chewing up the CPU for 100% doing literally nothing because we are forced to use polling...
		$deferred = new Deferred();
		$response = $this->uart->read_function(($max_wait_ms / 1000), 1024, 250000);

		if (!is_null($response) && $response->is_valid() == true)
		{
			$deferred->resolve($response);
		}
		else
		{
			$deferred->reject(smp_transport::error(smp_transport::SMP_TRANSPORT_ERROR_TIMEOUT));
		}

		return $deferred->promise();
	}

	public function max_message_data_size($mtu): int
	{
		$available_mtu = (float)$mtu;
		$packets = ceil($available_mtu / 124.0);

		//Convert to number of base64 encoded bytes
		$available_mtu = $available_mtu * 3.0 / 4.0;

		//Remove packet length and CRC (2 bytes each)
		$available_mtu -= 4.0;

		//Remove header and footer of each packet
		$available_mtu -= (float)$packets * 3.0;

		//Remove possible padding bytes for narrow final packets
		if (((int)$available_mtu % 93) >= 91)
		{
			$available_mtu -= 3.0;
		}
		else if (((int)$available_mtu % 93) >= 88)
		{
			$available_mtu -= 1.0;
		}

		return (int)$available_mtu;
	}

        public static function to_error_string($error_code)
        {
		if ($error_code > smp_transport::SMP_TRANSPORT_ERROR_TRANSPORT_DEFINED_START)
		{
			return parent::to_error_string($error_code);
		}

                return 'TODO';
	}
}
?>
