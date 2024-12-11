<?PHP
/******************************************************************************
** Copyright (C) 2024 Jamie M.
**
** Project: MCUmgrPHP
**
** Module: smp_group_shell.php
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
use CBOR\ByteStringObject;
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

class smp_group_shell extends smp_group
{
	const MODE_IDLE = 0;
	const MODE_EXECUTE = 1;

	const COMMAND_EXECUTE = 0;

	public const error_lookup = array(
		['COMMAND_TOO_LONG', 'The provided command to execute is too long'],
		['EMPTY_COMMAND', 'No command to execute was provided']
	);

	function __construct(&$processor)
	{
		parent::__construct($processor, "shell", smp_group::SMP_GROUP_ID_SHELL);
		$this->mode = smp_group_shell::MODE_IDLE;
	}

	private function setup($op, $command)
	{
		$this->active_promise = new \React\Promise\Deferred();
		$this->message = new smp_message();
		$this->message->start_message($op, 1, smp_group::SMP_GROUP_ID_SHELL, $command);
	}

	public function start_execute($arguments)
	{
		$this->setup(smp_message::SMP_OP_READ, smp_group_shell::COMMAND_COUNT);
		$argument_list = ListObject::create();

		foreach ($arguments as $argument)
		{
			$argument_list->add(TextStringObject::create($argument));
		}

		$this->message->contents()->add($argument_list);
		$this->message->end_message();
		$this->mode = smp_group_shell::MODE_COUNT;

		if ($this->check_message_before_send($this->message) == false)
		{
			$this->active_promise->reject(smp_transport::error(smp_transport::SMP_TRANSPORT_ERROR_MESSAGE_TOO_LARGE));
		}
		else
		{
			$this->processor->send($this->message, $this->smp_timeout, $this->smp_retries, true)->then(\Closure::fromCallable([$this, 'parse_shell_execute_response']), \Closure::fromCallable([$this, 'parse_failure']));
		}

		return $this->active_promise->promise();
	}

	private function parse_failure($error)
	{
		$this->active_promise->reject($error);
	}

	private function parse_shell_execute_response($message)
	{
		$cbor_data = $message->contents()->normalize();

		if (isset($cbor_data['o']) && isset($cbor_data['ret']))
		{
			$this->active_promise->resolve($cbor_data);
		}
		else
		{
			$this->active_promise->reject(smp_transport::error(smp_transport::SMP_TRANSPORT_ERROR_MESSAGE_RESPONSE_NOT_VALID));
		}
	}

	protected function cleanup()
	{
		$this->mode = smp_group_shell::MODE_IDLE;
	}
}
?>
