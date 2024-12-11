<?PHP
/******************************************************************************
** Copyright (C) 2024 Jamie M.
**
** Project: MCUmgrPHP
**
** Module: smp_group_zephyr.php
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

class smp_group_zephyr extends smp_group
{
	const MODE_IDLE = 0;
	const MODE_STORAGE_ERASE = 1;

	const COMMAND_STORAGE_ERASE = 0;

	public const error_lookup = array(
		['FLASH_OPEN_FAILED', 'Opening of the flash area has failed'],
		['FLASH_CONFIG_QUERY_FAIL', 'Querying the flash area parameters has failed'],
		['FLASH_ERASE_FAILED', 'Erasing the flash area has failed']
	);

	function __construct(&$processor)
	{
		parent::__construct($processor, "zephyr", smp_group::SMP_GROUP_ID_ZEPHYR);
		$this->mode = smp_group_zephyr::MODE_IDLE;
	}

	private function setup($op, $command)
	{
		$this->active_promise = new \React\Promise\Deferred();
		$this->message = new smp_message();
		$this->message->start_message($op, 1, smp_group::SMP_GROUP_ID_STAT, $command);
	}

	public function start_storage_erase()
	{
		$this->setup(smp_message::SMP_OP_WRITE, smp_group_zephyr::COMMAND_STORAGE_ERASE);
		$this->message->end_message();
		$this->mode = smp_group_zephyr::MODE_STORAGE_ERASE;

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

	protected function cleanup()
	{
		$this->mode = smp_group_zephyr::MODE_IDLE;
	}
}
?>
