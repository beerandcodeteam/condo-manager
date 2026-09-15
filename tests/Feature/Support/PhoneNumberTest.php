<?php

use App\Rules\E164Phone;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Validator;

dataset('valid phones', [
    'brazilian mobile with punctuation' => ['+55 (11) 99999-0000', '+5511999990000'],
    'us number with spaces' => ['+1 415 555 2671', '+14155552671'],
    'dotted number' => ['+55.41.3000.2200', '+554130002200'],
    'already normalized' => ['+5541998123344', '+5541998123344'],
    'plus sign decoded as a space from a query string' => [' 5511999990000', '+5511999990000'],
]);

dataset('invalid phones', [
    'missing plus sign' => ['11999990000'],
    'leading zero country code' => ['+0123'],
    'text' => ['telefone'],
    'too short' => ['+551234'],
    'too long' => ['+5511999990000123'],
    'letters mixed in' => ['+55 11 9999A-0000'],
]);

test('normalize strips punctuation and returns E.164', function (string $input, string $expected) {
    expect(PhoneNumber::normalize($input))->toBe($expected);
})->with('valid phones');

test('normalize returns null for invalid numbers', function (string $input) {
    expect(PhoneNumber::normalize($input))->toBeNull();
})->with('invalid phones');

test('normalize returns null for an empty string', function () {
    expect(PhoneNumber::normalize(''))->toBeNull();
});

test('format displays brazilian numbers with area code and hyphen', function (string $input, string $expected) {
    expect(PhoneNumber::format($input))->toBe($expected);
})->with([
    'mobile' => ['+5541998123344', '+55 41 99812-3344'],
    'landline' => ['+554130002200', '+55 41 3000-2200'],
]);

test('format keeps other countries as they are', function () {
    expect(PhoneNumber::format('+14155552671'))->toBe('+14155552671');
});

test('E164Phone rule accepts valid numbers', function (string $input) {
    expect(Validator::make(['phone' => $input], ['phone' => new E164Phone])->passes())->toBeTrue();
})->with('valid phones');

test('E164Phone rule rejects invalid numbers with pt-BR message', function (string $input) {
    $validator = Validator::make(['phone' => $input], ['phone' => new E164Phone]);

    expect($validator->errors()->first('phone'))->toBe('Informe o telefone no formato internacional, ex.: +5511999990000.');
})->with('invalid phones');

test('E164Phone rule rejects non-string values', function () {
    $validator = Validator::make(['phone' => ['+5511999990000']], ['phone' => new E164Phone]);

    expect($validator->fails())->toBeTrue();
});
