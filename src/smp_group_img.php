<?PHP
/******************************************************************************
** Copyright (C) 2024 Jamie M.
**
** Project: MCUmgrPHP
**
** Module: smp_group_img.php
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

class smp_group_img extends smp_group
{
	const MODE_IDLE = 0;
	const MODE_UPLOAD_FIRMWARE = 1;
	const MODE_LIST_IMAGES = 2;
	const MODE_SET_IMAGE = 3;
	const MODE_ERASE_IMAGE = 4;
	const MODE_SLOT_INFO = 5;

	const COMMAND_STATE = 0;
	const COMMAND_UPLOAD = 1;
	const COMMAND_ERASE = 5;
	const COMMAND_SLOT_INFO = 6;

	public const error_lookup = array(
		['FLASH_CONFIG_QUERY_FAIL', 'Failed to query flash area configuration'],
		['NO_IMAGE', 'There is no image in the slot'],
		['NO_TLVS', 'The image in the slot has no TLVs (tag, length, value)'],
		['INVALID_TLV', 'The image in the slot has an invalid TLV type and/or length'],
		['TLV_MULTIPLE_HASHES_FOUND', 'The image in the slot has multiple hash TLVs, which is invalid'],
		['TLV_INVALID_SIZE', 'The image in the slot has an invalid TLV size'],
		['HASH_NOT_FOUND', 'The image in the slot does not have a hash TLV, which is required'],
		['NO_FREE_SLOT', 'There is no free slot to place the image'],
		['FLASH_OPEN_FAILED', 'Flash area opening failed'],
		['FLASH_READ_FAILED', 'Flash area reading failed'],
		['FLASH_WRITE_FAILED', 'Flash area writing failed'],
		['FLASH_ERASE_FAILED', 'Flash area erase failed'],
		['INVALID_SLOT', 'The provided slot is not valid'],
		['NO_FREE_MEMORY', 'Insufficient heap memory (malloc failed)'],
		['FLASH_CONTEXT_ALREADY_SET', 'The flash context is already set'],
		['FLASH_CONTEXT_NOT_SET', 'The flash context is not set'],
		['FLASH_AREA_DEVICE_NULL', 'The device for the flash area is NULL'],
		['INVALID_PAGE_OFFSET', 'The offset for a page number is invalid'],
		['INVALID_OFFSET', 'The offset parameter was not provided and is required'],
		['INVALID_LENGTH', 'The length parameter was not provided and is required'],
		['INVALID_IMAGE_HEADER', 'The image length is smaller than the size of an image header'],
		['INVALID_IMAGE_HEADER_MAGIC', 'The image header magic value does not match the expected value'],
		['INVALID_HASH', 'The hash parameter provided is not valid'],
		['INVALID_FLASH_ADDRESS', 'The image load address does not match the address of the flash area'],
		['VERSION_GET_FAILED', 'Failed to get version of currently running application'],
		['CURRENT_VERSION_IS_NEWER', 'The currently running application is newer than the version being uploaded'],
		['IMAGE_ALREADY_PENDING', 'There is already an image operating pending'],
		['INVALID_IMAGE_VECTOR_TABLE', 'The image vector table is invalid'],
		['INVALID_IMAGE_TOO_LARGE', 'The image it too large to fit'],
		['INVALID_IMAGE_DATA_OVERRUN', 'The amount of data sent is larger than the provided image size'],
		['IMAGE_CONFIRMATION_DENIED', 'Confirmation of image has been denied'],
		['IMAGE_SETTING_TEST_TO_ACTIVE_DENIED', 'Setting test to active slot is not allowed']
	);

	function __construct(&$processor)
	{
		parent::__construct($processor, "img", smp_group::SMP_GROUP_ID_IMG);
		$this->mode = smp_group_img::MODE_IDLE;
	}

	private function setup($op, $command)
	{
		$this->active_promise = new \React\Promise\Deferred();
		$this->message = new smp_message();
		$this->message->start_message($op, 1, smp_group::SMP_GROUP_ID_IMG, $command);
	}

	public function start_image_get()
	{
		$this->setup(smp_message::SMP_OP_READ, smp_group_img::COMMAND_STATE);
		$this->message->end_message();
		$this->mode = smp_group_img::MODE_LIST_IMAGES;

		if ($this->check_message_before_send($this->message) == false)
		{
			$this->active_promise->reject(smp_transport::error(smp_transport::SMP_TRANSPORT_ERROR_MESSAGE_TOO_LARGE));
		}
		else
		{
			$this->processor->send($this->message, $this->smp_timeout, $this->smp_retries, true)->then(\Closure::fromCallable([$this, 'parse_image_get_response']), \Closure::fromCallable([$this, 'parse_failure']));
		}

		return $this->active_promise->promise();
	}

	public function start_image_set($hash, $confirm = false)
	{
		$this->setup(smp_message::SMP_OP_WRITE, smp_group_img::COMMAND_ETATE);

		if ($confirm == true)
		{
			$this->message->contents()->add(
				TextStringObject::create('hash'), TextStringObject::create($hash),
				TextStringObject::create('confirm'), ByteStringObject::create(true),
			);
		}
		else
		{
			$this->message->contents()->add(
				TextStringObject::create('hash'), TextStringObject::create($hash),
			);
		}

		$this->message->end_message();
		$this->mode = smp_group_img::MODE_SET_IMAGE;

		if ($this->check_message_before_send($this->message) == false)
		{
			$this->active_promise->reject(smp_transport::error(smp_transport::SMP_TRANSPORT_ERROR_MESSAGE_TOO_LARGE));
		}
		else
		{
			$this->processor->send($this->message, $this->smp_timeout, $this->smp_retries, true)->then(\Closure::fromCallable([$this, 'parse_image_get_response']), \Closure::fromCallable([$this, 'parse_failure']));
		}

		return $this->active_promise->promise();
	}

/*
	public function start_image_update($image = 0, $filename, $upgrade = false)
	{
		$this->setup(smp_message::SMP_OP_WRITE, smp_group_img::COMMAND_UPLOAD);
		$this->message->contents()->add(
			TextStringObject::create(''), TextStringObject::create($),
			TextStringObject::create(''), ByteStringObject::create($),
		);
		$this->message->end_message();
		$this->mode = smp_group_img::MODE_UPLOAD_FIRMWARE;

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
*/

	public function start_image_erase($slot = 1)
	{
		$this->setup(smp_message::SMP_OP_WRITE, smp_group_img::COMMAND_ERASE);

		if ($slot != 1)
		{
			$this->message->contents()->add(
				TextStringObject::create('slot'), UnsignedIntegerObject::create($slot),
			);
		}

		$this->message->end_message();
		$this->mode = smp_group_img::MODE_ERASE_IMAGE;

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

	public function start_image_slot_info()
	{
		$this->setup(smp_message::SMP_OP_READ, smp_group_img::COMMAND_SLOT_INFO);
		$this->message->end_message();
		$this->mode = smp_group_img::MODE_SLOT_INFO;

		if ($this->check_message_before_send($this->message) == false)
		{
			$this->active_promise->reject(smp_transport::error(smp_transport::SMP_TRANSPORT_ERROR_MESSAGE_TOO_LARGE));
		}
		else
		{
			$this->processor->send($this->message, $this->smp_timeout, $this->smp_retries, true)->then(\Closure::fromCallable([$this, 'parse_slot_info_response']), \Closure::fromCallable([$this, 'parse_failure']));
		}

		return $this->active_promise->promise();
	}

	private function parse_failure($error)
	{
		$this->active_promise->reject($error);
	}

	private function parse_image_get_response($message)
	{
		$cbor_data = $message->contents()->normalize();

		if (isset($cbor_data['images']))
		{
			$this->active_promise->resolve($cbor_data['images']);
		}
		else
		{
			$this->active_promise->reject(smp_transport::error(smp_transport::SMP_TRANSPORT_ERROR_MESSAGE_RESPONSE_NOT_VALID));
		}
	}

	private function parse_slot_info_response($message)
	{
		$cbor_data = $message->contents()->normalize();

		if (isset($cbor_data['images']))
		{
			$this->active_promise->resolve($cbor_data['images']);
		}
		else
		{
			$this->active_promise->reject(smp_transport::error(smp_transport::SMP_TRANSPORT_ERROR_MESSAGE_RESPONSE_NOT_VALID));
		}
	}

	protected function cleanup()
	{
		$this->mode = smp_group_img::MODE_IDLE;
	}
}
?>
