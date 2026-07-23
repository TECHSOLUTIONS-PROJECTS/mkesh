<?php

declare(strict_types=1);

namespace TechSolutions\Mkesh\Tests;

use PHPUnit\Framework\TestCase;
use TechSolutions\Mkesh\Error\ErrorCodes;
use TechSolutions\Mkesh\Exception\ErrorResponseException;

final class ErrorCodesTest extends TestCase
{
    public function test_known_codes_resolve_to_descriptions(): void
    {
        self::assertSame('Transaction not found', ErrorCodes::description('TRANSACTION_NOT_FOUND'));
        self::assertSame('Account not found', ErrorCodes::description('ACCOUNT_NOT_FOUND'));
        self::assertNotNull(ErrorCodes::description('ACCOUNTHOLDER_WITH_FRI_NOT_FOUND'));
        self::assertTrue(ErrorCodes::has('REFERENCE_ID_ALREADY_IN_USE'));
        self::assertTrue(ErrorCodes::has('COMMUNICATION_ERROR'));
    }

    public function test_unknown_code_returns_null(): void
    {
        self::assertNull(ErrorCodes::description('TOTALLY_MADE_UP_CODE'));
        self::assertFalse(ErrorCodes::has('TOTALLY_MADE_UP_CODE'));
    }

    public function test_catalogue_is_large_and_well_formed(): void
    {
        $all = ErrorCodes::all();
        self::assertGreaterThan(600, count($all));

        foreach ($all as $code => $description) {
            self::assertMatchesRegularExpression('/^[A-Z0-9_]+$/', $code);
            self::assertNotSame('', $description);
        }
    }

    public function test_exception_message_and_description_include_catalogue_text(): void
    {
        $e = new ErrorResponseException('ACCOUNTHOLDER_WITH_FRI_NOT_FOUND', ['fri' => 'FRI:258/MSISDN']);

        self::assertStringContainsString('Account holder with given FRI', $e->getMessage());
        self::assertStringContainsString('fri=FRI:258/MSISDN', $e->getMessage());
        self::assertSame('Account holder with given FRI could not be found', $e->getDescription());
    }
}
