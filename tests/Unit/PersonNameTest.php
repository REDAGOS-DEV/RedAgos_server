<?php

namespace Tests\Unit;

use App\Rules\PersonName;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A name is letters, with the punctuation real names carry; nothing else.
 */
class PersonNameTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function names(): array
    {
        return [
            'one word' => ['Maria'],
            'two words' => ['Dela Cruz'],
            'an abbreviation' => ['Ma. Luisa'],
            'a suffix' => ['Santos Jr.'],
            'a hyphen' => ['Santos-Reyes'],
            'a straight apostrophe' => ["O'Brien"],
            'a curly apostrophe' => ['O’Brien'],
            'an enye' => ['Peña'],
            'a decomposed accent' => ["Pen\u{0303}a"],
            'several accents' => ['José Ñiño'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function notNames(): array
    {
        return [
            'an emoji alone' => ['😀'],
            'an emoji after a name' => ['Maria 😀'],
            'an emoji inside a name' => ['Ma❤ria'],
            'a flag' => ['Juan 🇵🇭'],
            'a skin-toned emoji' => ['Juan 👍🏽'],
            'a keycap' => ['Juan 1️⃣'],
            'a variation selector left behind' => ["Maria\u{FE0F}"],
            'digits' => ['Maria2'],
            'symbols' => ['Maria@#'],
            'punctuation first' => ['-Maria'],
            'a space first' => [' Maria'],
        ];
    }

    #[DataProvider('names')]
    public function test_a_real_name_passes(string $name): void
    {
        $this->assertTrue(Validator::make(['name' => $name], ['name' => [new PersonName]])->passes());
    }

    #[DataProvider('notNames')]
    public function test_anything_but_letters_is_refused(string $name): void
    {
        $validator = Validator::make(['name' => $name], ['name' => [new PersonName]]);

        $this->assertTrue($validator->fails());
        $this->assertSame(
            'The name can only contain letters, spaces, hyphens, apostrophes and periods.',
            $validator->errors()->first('name'),
        );
    }
}
