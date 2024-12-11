<?PHP
/******************************************************************************
** Copyright (C) 2024 Jamie M.
**
** Project: MCUmgrPHP
**
** Module: smp_group_stat.php
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

class smp_group_stat extends smp_group
{
	const MODE_IDLE = 0;
	const MODE_GROUP_DATA = 1;
	const MODE_LIST_GROUPS = 2;

	const COMMAND_GROUP_DATA = 0;
	const COMMAND_LIST_GROUPS = 1;

	public const error_lookup = array(
		['INVALID_GROUP', 'The provided statistic group name was not found'],
		['INVALID_STAT_NAME', 'The provided statistic name was not found'],
		['INVALID_STAT_SIZE', 'The size of the statistic cannot be handled'],
		['WALK_ABORTED', 'Walk through of statistics was aborted']
	);

	function __construct(&$processor)
	{
		parent::__construct($processor, "stat", smp_group::SMP_GROUP_ID_STAT);
		$this->mode = smp_group_stat::MODE_IDLE;
	}

	private function setup($op, $command)
	{
		$this->active_promise = new \React\Promise\Deferred();
		$this->message = new smp_message();
		$this->message->start_message($op, 1, smp_group::SMP_GROUP_ID_STAT, $command);
	}

	public function start_group_data($name)
	{
		$this->setup(smp_message::SMP_OP_READ, smp_group_stat::COMMAND_GROUP_DATA);
		$this->message->contents()->add(
			TextStringObject::create('name'), TextStringObject::create($name)
		);
		$this->message->end_message();
		$this->mode = smp_group_stat::MODE_GROUP_DATA;

		if ($this->check_message_before_send($this->message) == false)
		{
			$this->active_promise->reject(smp_transport::error(smp_transport::SMP_TRANSPORT_ERROR_MESSAGE_TOO_LARGE));
		}
		else
		{
			$this->processor->send($this->message, $this->smp_timeout, $this->smp_retries, true)->then(\Closure::fromCallable([$this, 'parse_stat_group_data_response']), \Closure::fromCallable([$this, 'parse_failure']));
		}

		return $this->active_promise->promise();
	}

	public function start_list_groups()
	{
		$this->setup(smp_message::SMP_OP_READ, smp_group_stat::COMMAND_LIST_GROUPS);
		$argument_list = ListObject::create();
		$this->message->end_message();
		$this->mode = smp_group_stat::MODE_LIST_GROUPS;

		if ($this->check_message_before_send($this->message) == false)
		{
			$this->active_promise->reject(smp_transport::error(smp_transport::SMP_TRANSPORT_ERROR_MESSAGE_TOO_LARGE));
		}
		else
		{
			$this->processor->send($this->message, $this->smp_timeout, $this->smp_retries, true)->then(\Closure::fromCallable([$this, 'parse_stat_list_groups_response']), \Closure::fromCallable([$this, 'parse_failure']));
		}

		return $this->active_promise->promise();
	}

	private function parse_failure($error)
	{
		$this->active_promise->reject($error);
	}

	private function parse_stat_group_data_execute_response($message)
	{
		$cbor_data = $message->contents()->normalize();

		if (isset($cbor_data['fields']))
		{
			$this->active_promise->resolve($cbor_data['fields']);
		}
		else
		{
			$this->active_promise->reject(smp_transport::error(smp_transport::SMP_TRANSPORT_ERROR_MESSAGE_RESPONSE_NOT_VALID));
		}
	}

	private function parse_stat_list_groups_response($message)
	{
		$cbor_data = $message->contents()->normalize();

		if (isset($cbor_data['stat_list']))
		{
			$this->active_promise->resolve($cbor_data['stat_list']);
		}
		else
		{
			$this->active_promise->reject(smp_transport::error(smp_transport::SMP_TRANSPORT_ERROR_MESSAGE_RESPONSE_NOT_VALID));
		}
	}

	protected function cleanup()
	{
		$this->mode = smp_group_stat::MODE_IDLE;
	}
}
?>
