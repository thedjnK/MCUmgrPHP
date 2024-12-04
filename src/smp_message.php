<?PHP
namespace MCUmgr;

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

class smp_message
{
	public const SMP_OP_READ = 0;
	public const SMP_OP_READ_RESPONSE = 1;
	public const SMP_OP_WRITE = 2;
	public const SMP_OP_WRITE_RESPONSE = 3;
	private const SMP_OP_BITS = 0x3;

	private const SMP_VERSION_1_HEADER = 0x00;
	private const SMP_VERSION_2_HEADER = 0x08;

	private const SMP_HEADER_OFFSET_VERSION_OP = 0;
	private const SMP_HEADER_OFFSET_FLAGS = 1;
	private const SMP_HEADER_OFFSET_LENGTH_A = 2;
	private const SMP_HEADER_OFFSET_LENGTH_B = 3;
	private const SMP_HEADER_OFFSET_GROUP_A = 4;
	private const SMP_HEADER_OFFSET_GROUP_B = 5;
	private const SMP_HEADER_OFFSET_SEQUENCE = 6;
	private const SMP_HEADER_OFFSET_MESSAGE_ID = 7;
	private const SMP_HEADER_SIZE = 8;

	private $header_added = false;
	private $buffer = array();
	private $cbor = NULL;

	public function start_message_no_start_map($op, $version, $group, $id)
	{
		$i = 0;
		$this->buffer = array();

		$this->buffer[smp_message::SMP_HEADER_OFFSET_VERSION_OP] = chr(($version == 1 ? smp_message::SMP_VERSION_2_HEADER : smp_message::SMP_VERSION_1_HEADER) | $op);
		$this->buffer[smp_message::SMP_HEADER_OFFSET_FLAGS] = chr(0); /* Flags */
		$this->buffer[smp_message::SMP_HEADER_OFFSET_LENGTH_A] = chr(0); /* Length A */
		$this->buffer[smp_message::SMP_HEADER_OFFSET_LENGTH_B] = chr(0); /* Length B */
		$this->buffer[smp_message::SMP_HEADER_OFFSET_GROUP_A] = chr(($group & 0xff00) >> 8); /* Group A */
		$this->buffer[smp_message::SMP_HEADER_OFFSET_GROUP_B] = chr($group & 0xff); /* Group B */
		$this->buffer[smp_message::SMP_HEADER_OFFSET_SEQUENCE] = chr(0); /* Sequence */
		$this->buffer[smp_message::SMP_HEADER_OFFSET_MESSAGE_ID] = chr($id); /* Message ID */

		$this->header_added = true;
	}

	public function start_message($op, $version, $group, $id)
	{
		$this->start_message_no_start_map($op, $version, $group, $id);
		$this->cbor = MapObject::create();
	}

	public function append($data)
	{
		$binary_data = $data;

		if ($this->header_added == false)
		{
			$pos = 0;
			$append_size = smp_message::SMP_HEADER_SIZE - count($this->buffer);

			while ($append_size > 0 && $pos < strlen($binary_data))
			{
				$this->buffer[] = $binary_data[$pos];
				--$append_size;
				++$pos;
			}

			if (count($this->buffer) == smp_message::SMP_HEADER_SIZE)
			{
				$this->header_added = true;
			}

			$binary_data = substr($binary_data, $pos);
		}

		if (gettype($this->cbor) != 'string')
		{
			$this->cbor = '';
		}

		$this->cbor .= $binary_data;

		if ($this->is_valid() == true)
		{
			//Convert into CBOR
			$decoder = Decoder::create();
			$this->cbor = $decoder->decode(StringStream::create($this->cbor));
		}
	}

	public function data(): string
	{
		return implode('', $this->buffer).(string)$this->cbor;
	}

	public function &header(): array
	{
		return $this->buffer;
	}

	public function &contents()
	{
		return $this->cbor;
	}

	public function clear()
	{
		$this->header_added = false;
		$this->buffer = array();
		$this->cbor = NULL;
	}

	public function size(): int
	{
		if (is_null($this->cbor))
		{
			return count($this->buffer);
		}

		return count($this->buffer) + sizeof((string)$this->cbor);
	}

	public function is_valid(): bool
	{
		if ($this->header_added == false)
		{
			return false;
		}

		$length = (ord($this->buffer[smp_message::SMP_HEADER_OFFSET_LENGTH_A]) << 8) | ord($this->buffer[smp_message::SMP_HEADER_OFFSET_LENGTH_B]);

		if (strlen((string)$this->cbor) == $length)
		{
			return true;
		}

		return false;
	}

	public function end_message()
	{
		$length = (int)strlen((string)$this->cbor);
		$this->buffer[smp_message::SMP_HEADER_OFFSET_LENGTH_A] = chr(($length & 0xff00) >> 8); /* Length A */
		$this->buffer[smp_message::SMP_HEADER_OFFSET_LENGTH_B] = chr($length & 0xff); /* Length B */
	}

	public function end_custom_message(/*QByteArray data*/)
	{
	}

	public function header_version(): int
	{
		return (($this->buffer[smp_message::SMP_HEADER_OFFSET_VERSION_OP] & chr(smp_message::SMP_VERSION_2_HEADER)) == chr(smp_message::SMP_VERSION_2_HEADER) ? 1 : 0);
	}

	public function header_op(): int
	{
		return ord($this->buffer[smp_message::SMP_HEADER_OFFSET_VERSION_OP] & chr(smp_message::SMP_OP_BITS));
	}

	public function header_sequence(): int
	{
		return ord($this->buffer[smp_message::SMP_HEADER_OFFSET_SEQUENCE]);
	}

	public function header_group(): int
	{
		return ((ord($this->buffer[smp_message::SMP_HEADER_OFFSET_GROUP_A]) << 8) | ord($this->buffer[smp_message::SMP_HEADER_OFFSET_GROUP_B]));
	}

	public function header_message_id(): int
	{
		return ord($this->buffer[smp_message::SMP_HEADER_OFFSET_MESSAGE_ID]);
	}

	public function set_header_version($version)
	{
		if ($version == 1)
		{
			$this->buffer[smp_message::SMP_HEADER_OFFSET_VERSION_OP] |= chr(smp_message::SMP_VERSION_2_HEADER);
		}
		else
		{
			$this->buffer[smp_message::SMP_HEADER_OFFSET_VERSION_OP] &= chr(~smp_message::SMP_VERSION_2_HEADER);
		}
	}

	public function set_header_sequence($sequence): void
	{
		$this->buffer[smp_message::SMP_HEADER_OFFSET_SEQUENCE] = chr($sequence);
	}

	static public function response_op($op): int
	{
		return ($op == smp_message::SMP_OP_READ ? smp_message::SMP_OP_READ_RESPONSE : smp_message::SMP_OP_WRITE_RESPONSE);
	}
}
?>
