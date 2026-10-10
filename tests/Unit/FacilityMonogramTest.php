<?php

namespace Tests\Unit;

use App\Support\FacilityMonogram;
use PHPUnit\Framework\TestCase;

class FacilityMonogramTest extends TestCase
{
    public function test_it_takes_the_first_two_initials(): void
    {
        $this->assertSame('DR', FacilityMonogram::of('Davao Regional Blood Center'));
        $this->assertSame('SP', FacilityMonogram::of('Southern Philippines Medical Center'));
    }

    public function test_it_skips_the_small_joining_words(): void
    {
        $this->assertSame('BB', FacilityMonogram::of('Bureau of Blood Services'));
        $this->assertSame('PR', FacilityMonogram::of('The Philippine Red Cross'));
    }

    public function test_a_single_word_gives_one_initial_and_no_name_gives_none(): void
    {
        $this->assertSame('T', FacilityMonogram::of('Tagum'));
        $this->assertSame('', FacilityMonogram::of(null));
        $this->assertSame('', FacilityMonogram::of('  '));
    }

    public function test_it_ignores_words_that_do_not_start_with_a_letter(): void
    {
        $this->assertSame('BC', FacilityMonogram::of('Blood Center #2'));
        $this->assertSame('ÑM', FacilityMonogram::of('ñol Medical'));
    }
}
