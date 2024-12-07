<?PHP
/******************************************************************************
** Copyright (C) 2024 Jamie M.
**
** Project: MCUmgrPHP
**
** Module: smp_processor.php
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

use React\Promise\ExtendedPromiseInterface;
use React\Promise\Deferred;

class smp_processor
{
	private $sequence = 0;
	private $transport = NULL;
	private $last_message = NULL;
	private $last_message_version_check = false;
	private $last_message_version = 0;
	private $repeat_timer = NULL;
	private $repeat_times = 0;
	private $busy = false;
	private $message_timeout_ms = 0;
	private $active_promise = NULL;

	public function send(&$message, $timeout_ms, $repeats, $allow_version_check): \React\Promise\Promise
	{
		$this->active_promise = new \React\Promise\Deferred();
		$transport_error = smp_transport::SMP_TRANSPORT_ERROR_OK;

		if ($this->busy == true)
		{
			$transport_error = smp_transport::SMP_TRANSPORT_ERROR_PROCESSOR_BUSY;
		}
		else if ($this->transport->is_connected() == 0)
		{
			$transport_error = smp_transport::SMP_TRANSPORT_ERROR_NOT_CONNECTED;
		}

		if ($transport_error == smp_transport::SMP_TRANSPORT_ERROR_OK)
		{
			$this->last_message = &$message;

			//Set message sequence
			$message->set_header_sequence($this->sequence);
			$this->last_message_version_check = $allow_version_check;
			$this->last_message_version = $message->header_version();
			$this->message_timeout_ms = $timeout_ms;
			$this->repeat_times = $repeats;
			$this->busy = true;

			if ($message->is_valid())
			{
				$transport_error = $this->transport->send($message);

				if ($transport_error == smp_transport::SMP_TRANSPORT_ERROR_OK)
				{
					++$this->sequence;
					$this->transport->receive($timeout_ms)->then([$this, 'message_received'], [$this, 'message_timeout']);
				}
			}
			else
			{
				$transport_error = smp_transport::SMP_TRANSPORT_ERROR_MESSAGE_NOT_VALID;
			}
		}

		if ($transport_error != smp_transport::SMP_TRANSPORT_ERROR_OK)
		{
			$this->cleanup();
			$this->active_promise->reject(smp_transport::error($transport_error));
		}

		return $this->active_promise->promise();
	}

	public function is_busy(): bool
	{
		return $this->busy;
	}

	public function set_transport(&$transport)
	{
		$this->transport = &$transport;
	}

	public function max_message_data_size($mtu)
	{
		return $this->transport->max_message_data_size($mtu);
	}

	public function cancel()
	{
	}

	private function cleanup()
	{
		if ($this->busy == false)
		{
			return;
		}

		if (!is_null($this->last_message))
		{
			$this->last_message = NULL;
		}

		$this->repeat_times = 0;
		$this->busy = false;
	}

	//Not to be used publicly
	public function message_received($message)
	{
		if ($this->busy == false)
		{
			//Not busy so this message probably isn't wanted anymore
			return;
		}

		//Check if this an expected response
		if ($message->header_group() != $this->last_message->header_group())
		{
//log_error() << "Invalid group, expected " << last_message_header->nh_group << " got " << response_header->nh_group;
		}
		else if ($message->header_message_id() != $this->last_message->header_message_id())
		{
//log_error() << "Invalid command, expected " << last_message_header->nh_id << " got " << response_header->nh_id;
		}
		else if ($message->header_sequence() != $this->last_message->header_sequence())
		{
//log_error() << "Invalid sequence, expected " << last_message_header->nh_seq << " got " << response_header->nh_seq;
		}
		else if ($message->header_op() != smp_message::response_op($this->last_message->header_op()))
		{
//log_error() << "Invalid op, expected " << smp_message::response_op(last_message_header->nh_op) << " got " << response_header->nh_op;
		}
		else
		{
			//Headers look valid, clean up before triggering callback
			$this->cleanup();
			$cbor_data = $message->contents()->normalize();

			if (isset($cbor_data['err']))
			{
				//SMP version 2 error code
				if (isset($cbor_data['err']['group']) && isset($cbor_data['err']['rc']))
				{
					$this->active_promise->reject(new smp_error(smp_error::SMP_ERROR_RC, $cbor_data['err']['group'], $cbor_data['err']['rc']));
				}
				else
				{
				}
			}
			else if (isset($cbor_data['rc']))
			{
				//SMP version 1 error code
				$this->active_promise->reject(new smp_error(smp_error::SMP_ERROR_RC, NULL, $cbor_data['rc']));
			}
			else
			{
				//No SMP error code
				$this->active_promise->resolve($message);
			}
		}
	}

	//Not to be used publicly
	public function message_timeout($promise)
	{
		if ($this->repeat_times == 0)
		{
			$this->active_promise->reject(smp_transport::error(smp_transport::SMP_TRANSPORT_ERROR_TIMEOUT));
			return;
		}

		//If this is a version 2 message, try sending a version 1 packet to see if version 2 is unsupported by the server
		if ($this->last_message_version_check == true)
		{
			if ($this->last_message_version == 0)
			{
				$this->last_message_version = 1;
			}
			else
			{
				$this->last_message_version = 0;
			}

			$this->last_message->set_header_version($this->last_message_version);
		}

		//Resend message
		--$this->repeat_times;
		$transport_error = $this->transport->send($this->last_message);

		if ($transport_error != smp_transport::SMP_TRANSPORT_ERROR_OK)
		{
			$this->cleanup();
			$this->active_promise->reject(smp_transport::error($transport_error));
		}
		else
		{
			$this->transport->receive($this->message_timeout_ms)->then([$this, 'message_received'], [$this, 'message_timeout']);
		}
	}
}
?>
