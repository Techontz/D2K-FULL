<?php

namespace Tests\Unit;

use App\Support\LipaNamba;
use PHPUnit\Framework\TestCase;

/**
 * How a till number entered by a human is cleaned up and displayed.
 */
class LipaNambaTest extends TestCase
{
    public function test_separators_people_type_are_stripped(): void
    {
        $this->assertSame('123456', LipaNamba::normalise(' 123 456 '));
        $this->assertSame('552354', LipaNamba::normalise('552354'));
        $this->assertSame('255764224477', LipaNamba::normalise('+255 764 224 477'));
        $this->assertSame('0764224477', LipaNamba::normalise('0764-224-477'));
    }

    public function test_values_that_cannot_be_keyed_into_a_handset_are_refused(): void
    {
        $this->assertNull(LipaNamba::normalise('12A456'), 'Letters cannot be dialled.');
        $this->assertNull(LipaNamba::normalise('1234'), 'Too short to be a till.');
        $this->assertNull(LipaNamba::normalise('1234567890123'), 'Too long to be a till.');
        $this->assertNull(LipaNamba::normalise(''));
        $this->assertNull(LipaNamba::normalise(null));
    }

    public function test_display_grouping_never_strands_a_digit(): void
    {
        // Grouped from the right. Grouping from the left left a stray "21" on
        // the end of an eight-digit till, which is worse than not grouping.
        $this->assertSame('552354', LipaNamba::pretty('552354'));
        $this->assertSame('87 654 321', LipaNamba::pretty('87654321'));
        $this->assertSame('876 543 210', LipaNamba::pretty('876543210'));
        $this->assertSame('0764 224 477', LipaNamba::pretty('0764224477'));
        $this->assertSame('255 764 224 477', LipaNamba::pretty('255764224477'));
        $this->assertNull(LipaNamba::pretty('nonsense'));
    }
}
