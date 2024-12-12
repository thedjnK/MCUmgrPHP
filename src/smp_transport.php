<?PHP
/******************************************************************************
** Copyright (C) 2024 Jamie M.
**
** Project: MCUmgrPHP
**
** Module: smp_transport.php
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

abstract class smp_transport
{
	const SMP_TRANSPORT_ERROR_OK = 0;
	const SMP_TRANSPORT_ERROR_UNSUPPORTED = -1;
	const SMP_TRANSPORT_ERROR_NOT_CONNECTED = -2;
	const SMP_TRANSPORT_ERROR_ALREADY_CONNECTED = -3;
	const SMP_TRANSPORT_ERROR_NO_DATA = -4;
	const SMP_TRANSPORT_ERROR_PROCESSOR_BUSY = -5;
	const SMP_TRANSPORT_ERROR_INVALID_CONFIGURATION = -6;
	const SMP_TRANSPORT_ERROR_OPEN_FAILED = -7;

	const SMP_TRANSPORT_ERROR_MESSAGE_TOO_LARGE = -8;
	const SMP_TRANSPORT_ERROR_TIMEOUT = -9;
	const SMP_TRANSPORT_ERROR_MESSAGE_NOT_VALID = -10;
	const SMP_TRANSPORT_ERROR_MESSAGE_RESPONSE_NOT_VALID = -11;
	const SMP_TRANSPORT_ERROR_COMMAND_INVALID_VALUE = -12;
	const SMP_TRANSPORT_ERROR_TRANSPORT_DEFINED_START = -50;

	private const DEFAULT_TRANSPORT_RETRIES = 3;
	private const DEFAULT_TRANSPORT_TIMEOUT_MS = 3000;

	public static $error_array = array(
		smp_transport::SMP_TRANSPORT_ERROR_OK => 'No error',
		smp_transport::SMP_TRANSPORT_ERROR_UNSUPPORTED => 'Unsupported',
		smp_transport::SMP_TRANSPORT_ERROR_NOT_CONNECTED => 'Not connected',
		smp_transport::SMP_TRANSPORT_ERROR_ALREADY_CONNECTED => 'Already connected',
		smp_transport::SMP_TRANSPORT_ERROR_NO_DATA => 'No data',
		smp_transport::SMP_TRANSPORT_ERROR_PROCESSOR_BUSY => 'Processor busy',
		smp_transport::SMP_TRANSPORT_ERROR_INVALID_CONFIGURATION => 'Invalid configuration',
		smp_transport::SMP_TRANSPORT_ERROR_OPEN_FAILED => 'Open failed'
	);

	public static function to_error_string($error_code)
	{
		if (isset(smp_transport::$error_array[$error_code]))
		{
			return smp_transport::$error_array[$error_code];
		}

		return 'No such smp_transport error code';
	}

	public static function error($error_code)
	{
		return new \RuntimeException(smp_transport::to_error_string($error_code), $error_code);
	}

	public function connect(): int
	{
		return smp_transport::SMP_TRANSPORT_ERROR_UNSUPPORTED;
	}

	public function disconnect($force): int
	{
		return smp_transport::SMP_TRANSPORT_ERROR_UNSUPPORTED;
	}

	public function is_connected(): bool
	{
		return smp_transport::SMP_TRANSPORT_ERROR_UNSUPPORTED;
	}

	abstract public function send(&$message): int;
	abstract public function receive($max_wait_ms = 0): \React\Promise\Promise;

	public function max_message_data_size($mtu): int
	{
		return $mtu;
	}

	public function get_retries(): int
	{
		return smp_transport::DEFAULT_TRANSPORT_RETRIES;
	}

	public function get_timeout(): int
	{
		return smp_transport::DEFAULT_TRANSPORT_TIMEOUT_MS;
	}
}
?>
