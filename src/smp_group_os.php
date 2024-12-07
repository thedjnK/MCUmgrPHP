<?PHP
/******************************************************************************
** Copyright (C) 2024 Jamie M.
**
** Project: MCUmgrPHP
**
** Module: smp_group_os.php
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

use CBOR\Decoder;
use CBOR\MapObject;
use CBOR\OtherObject\UndefinedObject;
use CBOR\TextStringObject;
use CBOR\ListObject;
use CBOR\NegativeIntegerObject;
use CBOR\UnsignedIntegerObject;
use CBOR\OtherObject\TrueObject;
use CBOR\OtherObject\FalseObject;
use CBOR\OtherObject\NullObject;
use CBOR\Tag\DecimalFractionTag;
use CBOR\Tag\TimestampTag;
use CBOR\StringStream;

class smp_group_os extends smp_group
{
	const MODE_IDLE = 0;
	const MODE_ECHO = 1;
	const MODE_TASK_STATS = 2;
	const MODE_MEMORY_POOL = 3;
	const MODE_DATE_TIME_GET = 4;
	const MODE_DATE_TIME_SET = 5;
	const MODE_RESET = 6;
	const MODE_MCUMGR_PARAMETERS = 7;
	const MODE_OS_APPLICATION_INFO = 8;
	const MODE_BOOTLOADER_INFO = 9;

	const COMMAND_ECHO = 0;
	const COMMAND_TASK_STATS = 2;
	const COMMAND_MEMORY_POOL = 3;
	const COMMAND_DATE_TIME = 4;
	const COMMAND_RESET = 5;
	const COMMAND_MCUMGR_PARAMETERS = 6;
	const COMMAND_OS_APPLICATION_INFO = 7;
	const COMMAND_BOOTLOADER_INFO = 8;

	public const error_lookup = array(
		['INVALID_FORMAT', 'The provided format value is not valid'],
		['QUERY_YIELDS_NO_ANSWER', 'Query was not recognized'],
	);

	function __construct(&$processor)
	{
		parent::__construct($processor, "os", smp_group::SMP_GROUP_ID_OS);
		$this->mode = smp_group_os::MODE_IDLE;
	}

	private function setup($op, $command)
	{
		$this->active_promise = new \React\Promise\Deferred();
		$this->message = new smp_message();
		$this->message->start_message($op, 1, smp_group::SMP_GROUP_ID_OS, $command);
	}

	public function start_echo($data)
	{
		$this->setup(smp_message::SMP_OP_WRITE, smp_group_os::COMMAND_ECHO);
		$this->message->contents()->add(
			TextStringObject::create('d'), TextStringObject::create($data)
		);
		$this->message->end_message();

		$this->mode = smp_group_os::MODE_ECHO;

		if ($this->check_message_before_send($this->message) == false)
		{
			$this->active_promise->reject(smp_transport::error(smp_transport::SMP_TRANSPORT_ERROR_MESSAGE_TOO_LARGE));
		}
		else
		{
			$this->processor->send($this->message, $this->smp_timeout, $this->smp_retries, true)->then(\Closure::fromCallable([$this, 'parse_echo_response']), \Closure::fromCallable([$this, 'parse_failure']));
		}

		return $this->active_promise->promise();
	}

	public function start_task_stats()
	{
		$this->setup(smp_message::SMP_OP_READ, smp_group_os::COMMAND_TASK_STATS);
		return $this->processor->send($this->message, $this->smp_timeout, $this->smp_retries, true);
	}

	public function start_memory_pool()
	{
		$this->setup(smp_message::SMP_OP_READ, smp_group_os::COMMAND_MEMORY_POOL);
		return $this->processor->send($this->message, $this->smp_timeout, $this->smp_retries, true);
	}

	public function start_reset($force = false): \React\Promise\Promise
	{
		$this->setup(smp_message::SMP_OP_WRITE, smp_group_os::COMMAND_RESET);

		if ($force == true)
		{
			$this->message->contents()->add(
				TextStringObject::create('force'), TrueObject::create()
			);
		}

		$this->mode = smp_group_os::MODE_RESET;

		if ($this->check_message_before_send($this->message) == false)
		{
			$this->active_promise->reject(smp_transport::error(smp_transport::SMP_TRANSPORT_ERROR_MESSAGE_TOO_LARGE));
		}
		else
		{
			$this->processor->send($this->message, $this->smp_timeout, $this->smp_retries, true)->then(function($abc) {echo $abc.'MEIN'."\n"; $this->active_promise->resolve(0);}, function ($abc) {$this->active_promise->reject($abc);});
		}

		return $this->active_promise->promise();
	}

	public function start_mcumgr_parameters()
	{
		$this->setup(smp_message::SMP_OP_READ, smp_group_os::COMMAND_MCUMGR_PARAMETERS);
		return $this->processor->send($this->message, $this->smp_timeout, $this->smp_retries, true);
	}

	public function start_os_application_info($format = '')
	{
		$this->setup(smp_message::SMP_OP_READ, smp_group_os::COMMAND_OS_APPLICATION_INFO);

		if (strlen($format) > 0)
		{
			$this->message->contents()->add(
				TextStringObject::create('format'), TextStringObject::create($format)
			);
		}

		return $this->processor->send($this->message, $this->smp_timeout, $this->smp_retries, true);
	}

	public function start_date_time_get()
	{
		$this->setup(smp_message::SMP_OP_READ, smp_group_os::COMMAND_DATE_TIME);
		return $this->processor->send($this->message, $this->smp_timeout, $this->smp_retries, true);
	}

//	public function start_date_time_set($date_time)
//	{
//		$this->setup(smp_message::SMP_OP_WRITE, smp_group_os::COMMAND_DATE_TIME);
//		return $this->processor->send($this->message, $this->smp_timeout, $this->smp_retries, true);
//	}

	public function start_bootloader_info($query = 'bootloader')
	{
		$this->setup(smp_message::SMP_OP_READ, smp_group_os::COMMAND_BOOTLOADER_INFO);

		$this->message->contents()->add(
			TextStringObject::create('query'), TextStringObject::create($query)
		);

		return $this->processor->send($this->message, $this->smp_timeout, $this->smp_retries, true);
	}

	private function parse_failure($error)
	{
		$this->active_promise->reject($error);
	}

	private function parse_echo_response($message)
	{
		$cbor_data = $message->contents()->normalize();

		if (isset($cbor_data['r']))
		{
			$this->active_promise->resolve($cbor_data['r']);
		}
		else
		{
			$this->active_promise->reject(smp_transport::error(smp_transport::SMP_TRANSPORT_ERROR_MESSAGE_RESPONSE_NOT_VALID));
		}
	}

	protected function cleanup()
	{
		$this->mode = smp_group_os::MODE_IDLE;
	}
}
?>
