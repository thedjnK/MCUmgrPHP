<?PHP
/******************************************************************************
** Copyright (C) 2024 Jamie M.
**
** Project: MCUmgrPHP
**
** Module: smp_group_enum.php
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

class smp_group_enum extends smp_group
{
	const MODE_IDLE = 0;
	const MODE_COUNT = 1;
	const MODE_LIST = 2;
	const MODE_SINGLE = 3;
	const MODE_DETAILS = 4;

	const COMMAND_COUNT = 0;
	const COMMAND_LIST = 1;
	const COMMAND_SINGLE = 2;
	const COMMAND_DETAILS = 3;

	public const error_lookup = array(
		['TOO_MANY_GROUP_ENTRIES', 'Too many group entries were provided'],
		['INSUFFICIENT_HEAP_FOR_ENTRIES', 'Insufficient heap memory to store entry data']
	);

	function __construct(&$processor)
	{
		parent::__construct($processor, "enum", smp_group::SMP_GROUP_ID_ENUM);
		$this->mode = smp_group_enum::MODE_IDLE;
	}

	private function setup($op, $command)
	{
		$this->active_promise = new \React\Promise\Deferred();
		$this->message = new smp_message();
		$this->message->start_message($op, 1, smp_group::SMP_GROUP_ID_ENUM, $command);
	}

	public function start_enum_count()
	{
		$this->setup(smp_message::SMP_OP_READ, smp_group_enum::COMMAND_COUNT);
		$this->message->end_message();
		$this->mode = smp_group_enum::MODE_COUNT;

		if ($this->check_message_before_send($this->message) == false)
		{
			$this->active_promise->reject(smp_transport::error(smp_transport::SMP_TRANSPORT_ERROR_MESSAGE_TOO_LARGE));
		}
		else
		{
			$this->processor->send($this->message, $this->smp_timeout, $this->smp_retries, true)->then(\Closure::fromCallable([$this, 'parse_enum_count_response']), \Closure::fromCallable([$this, 'parse_failure']));
		}

		return $this->active_promise->promise();
	}

	public function start_enum_list()
	{
		$this->setup(smp_message::SMP_OP_READ, smp_group_enum::COMMAND_LIST);
		$this->message->end_message();
		$this->mode = smp_group_enum::MODE_LIST;

		if ($this->check_message_before_send($this->message) == false)
		{
			$this->active_promise->reject(smp_transport::error(smp_transport::SMP_TRANSPORT_ERROR_MESSAGE_TOO_LARGE));
		}
		else
		{
			$this->processor->send($this->message, $this->smp_timeout, $this->smp_retries, true)->then(\Closure::fromCallable([$this, 'parse_enum_list_response']), \Closure::fromCallable([$this, 'parse_failure']));
		}

		return $this->active_promise->promise();
	}

	public function start_enum_single($index)
	{
		$this->setup(smp_message::SMP_OP_READ, smp_group_enum::COMMAND_SINGLE);

		if ($index != 0)
		{
			$this->message->contents()->add(
				TextStringObject::create('index'), UnsignedIntegerObject::create($index)
			);
		}

		$this->message->end_message();
		$this->mode = smp_group_enum::MODE_SINGLE;

		if ($this->check_message_before_send($this->message) == false)
		{
			$this->active_promise->reject(smp_transport::error(smp_transport::SMP_TRANSPORT_ERROR_MESSAGE_TOO_LARGE));
		}
		else
		{
			$this->processor->send($this->message, $this->smp_timeout, $this->smp_retries, true)->then(\Closure::fromCallable([$this, 'parse_enum_single_response']), \Closure::fromCallable([$this, 'parse_failure']));
		}

		return $this->active_promise->promise();
	}

	public function start_enum_details(/*$groups*/)
	{
		$this->setup(smp_message::SMP_OP_READ, smp_group_enum::COMMAND_DETAILS);
//TODO: groups
		$this->message->end_message();
		$this->mode = smp_group_enum::MODE_DETAILS;

		if ($this->check_message_before_send($this->message) == false)
		{
			$this->active_promise->reject(smp_transport::error(smp_transport::SMP_TRANSPORT_ERROR_MESSAGE_TOO_LARGE));
		}
		else
		{
			$this->processor->send($this->message, $this->smp_timeout, $this->smp_retries, true)->then(\Closure::fromCallable([$this, 'parse_enum_details_response']), \Closure::fromCallable([$this, 'parse_failure']));
		}

		return $this->active_promise->promise();
	}

	private function parse_failure($error)
	{
		$this->active_promise->reject($error);
	}

	private function parse_enum_count_response($message)
	{
		$cbor_data = $message->contents()->normalize();

		if (isset($cbor_data['count']))
		{
			$this->active_promise->resolve($cbor_data['count']);
		}
		else
		{
			$this->active_promise->reject(smp_transport::error(smp_transport::SMP_TRANSPORT_ERROR_MESSAGE_RESPONSE_NOT_VALID));
		}
	}

	private function parse_enum_list_response($message)
	{
		$cbor_data = $message->contents()->normalize();

		if (isset($cbor_data['groups']))
		{
			$this->active_promise->resolve($cbor_data['groups']);
		}
		else
		{
			$this->active_promise->reject(smp_transport::error(smp_transport::SMP_TRANSPORT_ERROR_MESSAGE_RESPONSE_NOT_VALID));
		}
	}

	private function parse_enum_single_response($message)
	{
		$cbor_data = $message->contents()->normalize();

		if (isset($cbor_data['group']))
		{
			$this->active_promise->resolve($cbor_data);
		}
		else
		{
			$this->active_promise->reject(smp_transport::error(smp_transport::SMP_TRANSPORT_ERROR_MESSAGE_RESPONSE_NOT_VALID));
		}
	}

	private function parse_enum_details_response($message)
	{
		$cbor_data = $message->contents()->normalize();

		if (isset($cbor_data['groups']))
		{
			$this->active_promise->resolve($cbor_data['groups']);
		}
		else
		{
			$this->active_promise->reject(smp_transport::error(smp_transport::SMP_TRANSPORT_ERROR_MESSAGE_RESPONSE_NOT_VALID));
		}
	}

	protected function cleanup()
	{
		$this->mode = smp_group_enum::MODE_IDLE;
	}
}
?>
