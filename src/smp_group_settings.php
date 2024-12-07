<?PHP
/******************************************************************************
** Copyright (C) 2024 Jamie M.
**
** Project: MCUmgrPHP
**
** Module: smp_group_settings.php
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

class smp_group_settings extends smp_group
{
	const MODE_IDLE = 0;
	const MODE_READ = 1;
	const MODE_WRITE = 2;
	const MODE_DELETE = 3;
	const MODE_COMMIT = 4;
	const MODE_LOAD = 5;
	const MODE_SAVE = 6;

	const COMMAND_READ_WRITE = 0;
	const COMMAND_DELETE = 1;
	const COMMAND_COMMIT = 2;
	const COMMAND_LOAD_SAVE = 3;

	public const error_lookup = array(
		['KEY_TOO_LONG', 'The provided key name is too long to be used'],
		['KEY_NOT_FOUND', 'The provided key name does not exist'],
		['READ_NOT_SUPPORTED', 'The provided key name does not support being read'],
		['ROOT_KEY_NOT_FOUND', 'The provided root key name does not exist'],
		['WRITE_NOT_SUPPORTED', 'The provided key name does not support being written'],
		['DELETE_NOT_SUPPORTED', 'The provided key name does not support being deleted'],
	);

	function __construct(&$processor)
	{
		parent::__construct($processor, "settings", smp_group::SMP_GROUP_ID_SETTINGS);
		$this->mode = smp_group_settings::MODE_IDLE;
	}

	private function setup($op, $command)
	{
		$this->active_promise = new \React\Promise\Deferred();
		$this->message = new smp_message();
		$this->message->start_message($op, 1, smp_group::SMP_GROUP_ID_SETTINGS, $command);
	}

	public function start_read($name, $max_length = 0)
	{
		$this->setup(smp_message::SMP_OP_READ, smp_group_settings::COMMAND_READ_WRITE);
		$this->message->contents()->add(
			TextStringObject::create('name'), TextStringObject::create($name),
//TODO:
//			TextStringObject::create('max_size'), ByteStringObject::create($max_length),
		);
		$this->message->end_message();
		$this->mode = smp_group_settings::MODE_READ;

		if ($this->check_message_before_send($this->message) == false)
		{
			$this->active_promise->reject(smp_transport::error(smp_transport::SMP_TRANSPORT_ERROR_MESSAGE_TOO_LARGE));
		}
		else
		{
			$this->processor->send($this->message, $this->smp_timeout, $this->smp_retries, true)->then(\Closure::fromCallable([$this, 'parse_read_response']), \Closure::fromCallable([$this, 'parse_failure']));
		}

		return $this->active_promise->promise();
	}

	public function start_write($name, $value)
	{
		$this->setup(smp_message::SMP_OP_WRITE, smp_group_settings::COMMAND_READ_WRITE);
		$this->message->contents()->add(
			TextStringObject::create('name'), TextStringObject::create($name),
			TextStringObject::create('val'), ByteStringObject::create($value),
		);
		$this->message->end_message();
		$this->mode = smp_group_settings::MODE_WRITE;

		if ($this->check_message_before_send($this->message) == false)
		{
			$this->active_promise->reject(smp_transport::error(smp_transport::SMP_TRANSPORT_ERROR_MESSAGE_TOO_LARGE));
		}
		else
		{
			$this->processor->send($this->message, $this->smp_timeout, $this->smp_retries, true)->then($this->active_promise->resolve(0), \Closure::fromCallable([$this, 'parse_failure']));
		}

		return $this->active_promise->promise();
	}

	public function start_delete($name)
	{
		$this->setup(smp_message::SMP_OP_WRITE, smp_group_settings::COMMAND_DELETE);
		$this->message->contents()->add(
			TextStringObject::create('name'), TextStringObject::create($name),
		);
		$this->message->end_message();
		$this->mode = smp_group_settings::MODE_DELETE;

		if ($this->check_message_before_send($this->message) == false)
		{
			$this->active_promise->reject(smp_transport::error(smp_transport::SMP_TRANSPORT_ERROR_MESSAGE_TOO_LARGE));
		}
		else
		{
			$this->processor->send($this->message, $this->smp_timeout, $this->smp_retries, true)->then($this->active_promise->resolve(0), \Closure::fromCallable([$this, 'parse_failure']));
		}

		return $this->active_promise->promise();
	}

	public function start_commit($data)
	{
		$this->setup(smp_message::SMP_OP_WRITE, smp_group_settings::COMMAND_COMMIT);
		$this->message->end_message();
		$this->mode = smp_group_settings::MODE_COMMIT;

		if ($this->check_message_before_send($this->message) == false)
		{
			$this->active_promise->reject(smp_transport::error(smp_transport::SMP_TRANSPORT_ERROR_MESSAGE_TOO_LARGE));
		}
		else
		{
			$this->processor->send($this->message, $this->smp_timeout, $this->smp_retries, true)->then($this->active_promise->resolve(0), \Closure::fromCallable([$this, 'parse_failure']));
		}

		return $this->active_promise->promise();
	}

	public function start_load($data)
	{
		$this->setup(smp_message::SMP_OP_READ, smp_group_settings::COMMAND_LOAD);
		$this->message->end_message();
		$this->mode = smp_group_settings::MODE_LOAD;

		if ($this->check_message_before_send($this->message) == false)
		{
			$this->active_promise->reject(smp_transport::error(smp_transport::SMP_TRANSPORT_ERROR_MESSAGE_TOO_LARGE));
		}
		else
		{
			$this->processor->send($this->message, $this->smp_timeout, $this->smp_retries, true)->then($this->active_promise->resolve(0), \Closure::fromCallable([$this, 'parse_failure']));
		}

		return $this->active_promise->promise();
	}

	public function start_save($data)
	{
		$this->setup(smp_message::SMP_OP_WRITE, smp_group_settings::COMMAND_SAVE);
		$this->message->end_message();
		$this->mode = smp_group_settings::MODE_SAVE;

		if ($this->check_message_before_send($this->message) == false)
		{
			$this->active_promise->reject(smp_transport::error(smp_transport::SMP_TRANSPORT_ERROR_MESSAGE_TOO_LARGE));
		}
		else
		{
			$this->processor->send($this->message, $this->smp_timeout, $this->smp_retries, true)->then($this->active_promise->resolve(0), \Closure::fromCallable([$this, 'parse_failure']));
		}

		return $this->active_promise->promise();
	}

	private function parse_failure($error)
	{
		$this->active_promise->reject($error);
	}

	private function parse_read_response($message)
	{
		$cbor_data = $message->contents()->normalize();

		if (isset($cbor_data['val']))
		{
			$this->active_promise->resolve($cbor_data['val']);
		}
		else
		{
			$this->active_promise->reject(smp_transport::error(smp_transport::SMP_TRANSPORT_ERROR_MESSAGE_RESPONSE_NOT_VALID));
		}
	}

	protected function cleanup()
	{
		$this->mode = smp_group_settings::MODE_IDLE;
	}
}
?>
