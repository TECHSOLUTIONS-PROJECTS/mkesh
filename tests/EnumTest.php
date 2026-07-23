<?php

declare(strict_types=1);

namespace BrilliantMind\Mkesh\Tests;

use BrilliantMind\Mkesh\Callback\CallbackResponse;
use BrilliantMind\Mkesh\Enum\CallbackResponseCode;
use BrilliantMind\Mkesh\Enum\ErrorCode;
use BrilliantMind\Mkesh\Enum\TransactionStatus;
use BrilliantMind\Mkesh\Error\ErrorCodes;
use BrilliantMind\Mkesh\Exception\ErrorResponseException;
use PHPUnit\Framework\TestCase;

final class EnumTest extends TestCase
{
    /**
     * Every enumerated code must exist in the platform catalogue — otherwise
     * description() silently returns null and we are branching on a code the
     * platform never sends.
     */
    public function test_every_error_code_case_exists_in_the_catalogue(): void
    {
        foreach (ErrorCode::cases() as $case) {
            if ($case === ErrorCode::UNKNOWN) {
                continue;
            }

            self::assertTrue(
                ErrorCodes::has($case->value),
                sprintf('%s is not a known platform error code.', $case->value),
            );
            self::assertNotNull($case->description());
        }
    }

    public function test_unknown_error_codes_do_not_throw(): void
    {
        self::assertSame(ErrorCode::UNKNOWN, ErrorCode::fromWire('SOMETHING_NEW'));
        self::assertSame(ErrorCode::UNKNOWN, ErrorCode::fromWire(null));
        self::assertSame(ErrorCode::UNKNOWN, ErrorCode::fromWire(''));
        self::assertSame(
            ErrorCode::TRANSACTION_NOT_FOUND,
            ErrorCode::fromWire('  transaction_not_found  '),
        );
    }

    public function test_error_code_classification(): void
    {
        self::assertTrue(ErrorCode::TRANSACTION_NOT_FOUND->isRetryable());
        self::assertFalse(ErrorCode::AUTHORIZATION_CURRENT_BALANCE_TOO_LOW->isRetryable());

        self::assertTrue(ErrorCode::REFERENCE_ID_ALREADY_IN_USE->isDuplicate());
        self::assertFalse(ErrorCode::TRANSACTION_NOT_FOUND->isDuplicate());

        self::assertTrue(ErrorCode::AUTHORIZATION_CURRENT_BALANCE_TOO_LOW->isCustomerFault());
        self::assertTrue(ErrorCode::INCORRECT_PIN->isCustomerFault());
        self::assertFalse(ErrorCode::AUTHORIZATION_FAILED->isCustomerFault());

        self::assertTrue(ErrorCode::TRANSACTION_REQUEST_EXPIRED->isExpired());
        self::assertTrue(ErrorCode::AUTHORIZATION_FAILED->isAuthFailure());

        // UNKNOWN must be inert: never retried, never blamed on the customer.
        self::assertFalse(ErrorCode::UNKNOWN->isRetryable());
        self::assertFalse(ErrorCode::UNKNOWN->isCustomerFault());
        self::assertFalse(ErrorCode::UNKNOWN->isDuplicate());
    }

    public function test_exception_exposes_both_the_raw_code_and_the_enum(): void
    {
        $e = new ErrorResponseException('REFERENCE_ID_ALREADY_IN_USE');

        self::assertSame('REFERENCE_ID_ALREADY_IN_USE', $e->getErrorCode());
        self::assertSame(ErrorCode::REFERENCE_ID_ALREADY_IN_USE, $e->code());
        self::assertTrue($e->code()->isDuplicate());

        // is() accepts enum cases and raw strings interchangeably.
        self::assertTrue($e->is(ErrorCode::REFERENCE_ID_ALREADY_IN_USE));
        self::assertTrue($e->is('REFERENCE_ID_ALREADY_IN_USE'));
        self::assertTrue($e->is(ErrorCode::TRANSACTION_NOT_FOUND, 'REFERENCE_ID_ALREADY_IN_USE'));
        self::assertFalse($e->is(ErrorCode::TRANSACTION_NOT_FOUND));
    }

    /** A code outside the enum keeps its raw string but maps to UNKNOWN. */
    public function test_exception_with_uncatalogued_code(): void
    {
        $e = new ErrorResponseException('BRAND_NEW_PLATFORM_CODE');

        self::assertSame('BRAND_NEW_PLATFORM_CODE', $e->getErrorCode());
        self::assertSame(ErrorCode::UNKNOWN, $e->code());
        self::assertTrue($e->is('BRAND_NEW_PLATFORM_CODE'));
    }

    public function test_transaction_status_aliases_and_settlement(): void
    {
        self::assertSame(TransactionStatus::SUCCESSFUL, TransactionStatus::fromWire('SUCCESS'));
        self::assertSame(TransactionStatus::FAILED, TransactionStatus::fromWire('failure'));
        self::assertSame(TransactionStatus::PENDING, TransactionStatus::fromWire('APPROVAL_REQUESTING_PENDING'));
        self::assertSame(TransactionStatus::UNKNOWN, TransactionStatus::fromWire('WHATEVER'));

        self::assertTrue(TransactionStatus::SUCCESSFUL->isSettled());
        self::assertTrue(TransactionStatus::FAILED->isSettled());
        self::assertFalse(TransactionStatus::PENDING->isSettled());
        self::assertFalse(TransactionStatus::UNKNOWN->isSettled());
    }

    public function test_callback_response_code(): void
    {
        self::assertTrue(CallbackResponseCode::SUCCESS->isSuccess());
        self::assertFalse(CallbackResponseCode::FAILURE->isSuccess());

        self::assertSame(CallbackResponseCode::SUCCESS, CallbackResponse::success()->code);
        self::assertSame(CallbackResponseCode::FAILURE, CallbackResponse::failure()->code);

        self::assertSame(
            '<?xml version="1.0" encoding="utf-8"?><ResponseCode>FAILURE</ResponseCode>',
            CallbackResponse::of(CallbackResponseCode::FAILURE)->toXml(),
        );

        // The object stringifies to the body, so `return response("$ack")` works.
        self::assertSame(
            CallbackResponse::success()->toXml(),
            (string) CallbackResponse::success(),
        );
    }
}
