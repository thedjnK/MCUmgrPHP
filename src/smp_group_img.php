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

	//MCUboot TLV (Tag-Length-Value) related constants
	private const image_tlv_magic = "\x07\x69";
	private const image_tlv_magic_reverse_endian = "\x69\x07";
	private const image_tlv_tag_sha256 = 0x10;
	private const image_tlv_tag_sha384 = 0x11;
	private const image_tlv_tag_sha512 = 0x12;
	private const sha256_size = 32;
	private const sha384_size = 48;
	private const sha512_size = 64;
	private const image_tlv_magic_size = 2;
	private const image_tlv_legnth_offset_1 = 2;
	private const image_tlv_legnth_offset_2 = 3;
	private const image_tlv_header_size = 4;
	private const image_tlv_data_header_size = 4;

	//MCUboot header constants
	private const ih_magic_none = "\xff\xff\xff\xff";
	private const ih_magic_v1 = "\x96\xf3\xb8\x3c";
	private const ih_magic_v2 = "\x96\xf3\xb8\x3d";
	private const ih_hdr_size_offs = 8;
	private const ih_protected_tlv_size_offs = 10;
	private const ih_img_size_offs = 12;

	private const endian_big = 0;
	private const endian_little = 1;
	private const endian_unknown = 2;

	private const match_not_present = 0;
	private const match_failed = 1;
	private const match_passed = 2;

	private const int32_max = 2147483647;

	private $upload_image = 0;
	private $file_upload_data = '';
	private $file_upload_area = 0;
	private $upload_time = 0;
	private $upload_hash = '';
	private $upload_endian = smp_group_img::endian_unknown;
	private $upgrade_only = false;
	private $upload_repeated_parts = 0;

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

	private function setup_existing($op, $command)
	{
		$this->message = new smp_message();
		$this->message->start_message($op, 1, smp_group::SMP_GROUP_ID_IMG, $command);
	}

	private function extract_header(&$file_data, &$endian)
	{
		$mcuboot_magic = array();

		if (strlen($file_data) < strlen(self::ih_magic_none))
		{
			return false;
		}

		$mcuboot_magic = substr($file_data, 0, strlen(self::ih_magic_none));

		if ($mcuboot_magic == self::ih_magic_v2 || $mcuboot_magic == self::ih_magic_v1)
		{
			$endian = self::endian_big;
		}
		else if ($mcuboot_magic == self::ih_magic_none)
		{
			//As header is empty, it is not possible to know if this is big or little endian
			$endian = self::endian_unknown;
		}
		else
		{
			$mcuboot_magic = strrev($mcuboot_magic);

			if ($mcuboot_magic == self::ih_magic_v2 || $mcuboot_magic == self::ih_magic_v1)
			{
				$endian = self::endian_little;
			}
			else
			{
				return false;
			}
		}

		return true;
	}

	private function extract_hash(&$file_data, &$hash)
	{
		$found = false;
		$hash_found = false;

		$hdr_size = 0;
		$protected_tlv_size = 0;
		$img_size = 0;

		if ($this->upload_endian != self::endian_big)
		{
			$hdr_size = ord($file_data[self::ih_hdr_size_offs]);
			$hdr_size |= ord($file_data[self::ih_hdr_size_offs + 1]) << 8;

			$protected_tlv_size = ord($file_data[self::ih_protected_tlv_size_offs]);
			$protected_tlv_size |= ord($file_data[self::ih_protected_tlv_size_offs + 1]) << 8;

			$img_size = ord($file_data[self::ih_img_size_offs]);
			$img_size |= ord($file_data[self::ih_img_size_offs + 1]) << 8;
			$img_size |= ord($file_data[self::ih_img_size_offs + 2]) << 16;
			$img_size |= ord($file_data[self::ih_img_size_offs + 3]) << 24;
		}
		else
		{
			$hdr_size = ord($file_data[self::ih_hdr_size_offs + 1]);
			$hdr_size |= ord($file_data[self::ih_hdr_size_offs]) << 8;

			$protected_tlv_size = ord($file_data[self::ih_protected_tlv_size_offs + 1]);
			$protected_tlv_size |= ord($file_data[self::ih_protected_tlv_size_offs]) << 8;

			$img_size = ord($file_data[self::ih_img_size_offs + 3]);
			$img_size |= ord($file_data[self::ih_img_size_offs + 2]) << 8;
			$img_size |= ord($file_data[self::ih_img_size_offs + 1]) << 16;
			$img_size |= ord($file_data[self::ih_img_size_offs]) << 24;
		}

		if ($img_size >= self::int32_max)
		{
			return false;
		}

		$pos = $hdr_size + $protected_tlv_size + $img_size;
		$tlv_area_length = 0;

		while (($pos + self::image_tlv_magic_size) < strlen($file_data))
		{
			if (($this->upload_endian != self::endian_big && substr($file_data, $pos, self::image_tlv_magic_size) == self::image_tlv_magic) || ($this->upload_endian == self::endian_big && substr($file_data, $pos, self::image_tlv_magic_size) == self::image_tlv_magic_reverse_endian))
			{
				if ($this->upload_endian != self::endian_big)
				{
					$tlv_area_length = ord($file_data[$pos + self::image_tlv_legnth_offset_1]);
					$tlv_area_length |= ord($file_data[$pos + self::image_tlv_legnth_offset_2]) << 8;
				}
				else
				{
					$tlv_area_length = ord($file_data[$pos + self::image_tlv_legnth_offset_2]);
					$tlv_area_length |= ord($file_data[$pos + self::image_tlv_legnth_offset_1]) << 8;
				}

				$found = true;
				break;
			}

			++$pos;
		}

		if ($found == true)
		{
			$new_pos = $pos + self::image_tlv_header_size;

			while ($new_pos < $pos + $tlv_area_length)
			{
				//TODO: TLVs are > 8-bit
				$type = ord($file_data[$new_pos]);
				$local_length = 0;

				if ($this->upload_endian != self::endian_big)
				{
					$local_length = ord($file_data[$new_pos + self::image_tlv_legnth_offset_1]);
					$local_length |= ord($file_data[$new_pos + self::image_tlv_legnth_offset_2]) << 8;
				}
				else
				{
					$local_length = ord($file_data[$new_pos + self::image_tlv_legnth_offset_2]);
					$local_length |= ord($file_data[$new_pos + self::image_tlv_legnth_offset_1]) << 8;
				}

				if ($type == self::image_tlv_tag_sha256 || $type == self::image_tlv_tag_sha384 || $type == self::image_tlv_tag_sha512)
				{
					$hash_size = ($type == self::image_tlv_tag_sha256 ? self::sha256_size : ($type == self::image_tlv_tag_sha384 ? self::sha384_size : self::sha512_size));

					if ($hash_found == true)
					{
						//Duplicate hash has been found
						return false;
					}

					if ($local_length == $hash_size)
					{
						//We have the hash we wanted
						$hash = substr($file_data, ($new_pos + 4), $local_length);
						$hash_found = true;
					}
					else
					{
						//Invalid length hash found
					}
				}

				$new_pos += $local_length + 4;
			}
		}

		return $hash_found;
	}

	private function parse_image_update_response($message)
	{
		if (!is_null($message))
		{
			$cbor_data = $message->contents()->normalize();
			$match = self::match_not_present;

			if (!isset($cbor_data['off']))
			{
				$this->active_promise->reject(smp_transport::error(smp_transport::SMP_TRANSPORT_ERROR_MESSAGE_RESPONSE_NOT_VALID));
				$this->cleanup();
				return;
			}

			if ($cbor_data['off'] != -1 /*&& $cbor_data['rc'] != 9*/)
			{
				if ($cbor_data['off'] < $this->file_upload_area)
				{
					++$this->upload_repeated_parts;

					if ($this->upload_repeated_parts > 3)
					{
						//Repeated lower offset 3 times, going in loop uploading the same thing over and over
					}
				}
				else
				{
					$this->upload_repeated_parts = 0;
				}

				$this->file_upload_area = $cbor_data['off'];
			}
			else
			{
				$this->upload_repeated_parts = 0;
			}

			if ($this->file_upload_area != 0)
			{
			}
		}

		//Upload next chunk
		$max_size = $this->processor->max_message_data_size($this->smp_mtu);

		if ($this->file_upload_area >= strlen($this->file_upload_data))
		{
			$prefix = 0;
			$speed_string = '';
			$upload_speed = (float)(time() - $this->upload_time);

			//If upload was completed in under a second, change to use time of 1 second (estimated speed is inaccurate anyway)
			if ($upload_speed < 1.0)
			{
				$upload_speed = 1.0;
			}

			$upload_speed = (float)strlen($this->file_upload_data) / $upload_speed;

			while ($upload_speed >= 1024.0 && $prefix < 3)
			{
				if (is_nan($upload_speed) == true || is_infinite($upload_speed) == true)
				{
					break;
				}

				$upload_speed /= 1024.0;
				++$prefix;
			}

			if (is_nan($upload_speed) == false && is_infinite($upload_speed) == false)
			{
				if ($prefix == 0)
				{
					$speed_string = "B";
				}
				else if ($prefix == 1)
				{
					$speed_string = "KiB";
				}
				else if ($prefix == 2)
				{
					$speed_string = "MiB";
				}
				else if ($prefix == 3)
				{
					$speed_string = "GiB";
				}

				$speed_string = '~'.round($upload_speed, 3).$speed_string.'ps throughput';
			}

			if (is_nan($upload_speed) == true || is_infinite($upload_speed) == true)
			{
				$speed_string = "Upload finished";
			}

//TODO:
echo "upload complete: ".$speed_string."\n";

			$this->cleanup();
			return;
		}

		$this->setup_existing(smp_message::SMP_OP_WRITE, smp_group_img::COMMAND_UPLOAD);

		if ($this->file_upload_area == 0)
		{
			//Initial packet, extra data is needed: generate upload hash
			$session_hash = hash('sha256', $this->file_upload_data, true);

			if ($this->upload_image != 0)
			{
				$this->message->contents()->add(TextStringObject::create('image'), UnsignedIntegerObject::create($this->upload_image));
			}

			$this->message->contents()->add(TextStringObject::create('len'), UnsignedIntegerObject::create(strlen($this->file_upload_data)));
			$this->message->contents()->add(TextStringObject::create('sha'), ByteStringObject::create($session_hash));

			if ($this->upgrade_only == true)
			{
				$this->message->contents()->add();
			}
		}

		$this->message->contents()->add(TextStringObject::create('off'), UnsignedIntegerObject::create($this->file_upload_area));

		//CBOR element header is 2 bytes with 1 byte end token for byte string data, have to include 1 byte header and 4 byte data for 'data' element too
		$max_size = $max_size - strlen($this->message->data()) - 3 - 5;
		$this->message->contents()->add(TextStringObject::create('data'), ByteStringObject::create(substr($this->file_upload_data, $this->file_upload_area, $max_size)));
		$this->message->end_message();

		if ($this->check_message_before_send($this->message) == false)
		{
			$this->active_promise->reject(smp_transport::error(smp_transport::SMP_TRANSPORT_ERROR_MESSAGE_TOO_LARGE));
		}
		else
		{
			$this->processor->send($this->message, $this->smp_timeout, $this->smp_retries, true)->then(\Closure::fromCallable([$this, 'parse_image_update_response']), \Closure::fromCallable([$this, 'parse_failure']));
		}
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
		$this->setup(smp_message::SMP_OP_WRITE, smp_group_img::COMMAND_STATE);

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

	public function start_image_update($image = 0, $filename, $upgrade = false)
	{
		$this->active_promise = new \React\Promise\Deferred();
		$this->mode = smp_group_img::MODE_UPLOAD_FIRMWARE;
		$this->upload_image = $image;
		$this->file_upload_data = file_get_contents($filename);;
		$this->file_upload_area = 0;
		$this->upgrade_only = $upgrade;
		$this->upload_repeated_parts = 0;
		$this->upload_time = time();

		if ($this->extract_header($this->file_upload_data, $this->upload_endian) == false || $this->extract_hash($this->file_upload_data, $this->upload_hash) == false)
		{
			$this->cleanup();
			$this->active_promise->reject(smp_transport::error(smp_transport::SMP_TRANSPORT_ERROR_COMMAND_INVALID_VALUE));
		}
		else
		{
			$this->parse_image_update_response(NULL);
		}

		return $this->active_promise->promise();
	}

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
		if ($this->mode = smp_group_img::MODE_UPLOAD_FIRMWARE)
		{
			$this->upload_image = 0;
			$this->file_upload_data = '';
			$this->upload_hash = '';
			$this->file_upload_area = 0;
			$this->upload_endian = self::endian_unknown;
			$this->upgrade_only = false;
			$this->upload_repeated_parts = 0;
			$this->upload_time = 0;
		}

		$this->mode = smp_group_img::MODE_IDLE;
	}
}
?>
