<?php

declare(strict_types=1);

/**
 * Zero-dependency check script for the receipt-calculator fixture.
 *
 * ponytail: this uses a hand-rolled check() rather than PHP's own assert()
 * because assert() is a no-op when zend.assertions=-1 (a common production
 * php.ini default) — a test built on it can "pass" without ever evaluating
 * the condition, which is exactly the "test that cannot fail" trap this
 * project has been burned by before. check() always evaluates.
 *
 * Run: php tests/run.php
 * Exit code 0 only if every check passes.
 */

require __DIR__.'/../src/Cart.php';
require __DIR__.'/../src/Receipt.php';

$discountCodePath = __DIR__.'/../src/DiscountCode.php';
if (file_exists($discountCodePath)) {
    require $discountCodePath;
}

$failures = 0;

function check(bool $condition, string $message): void
{
    global $failures;

    if ($condition) {
        echo "PASS: {$message}\n";
    } else {
        echo "FAIL: {$message}\n";
        $failures++;
    }
}

// --- (a) Cart without a code taxes the full subtotal. Must already pass —
// proves the fixture is not broken generally, before Paider touches it. ---

$cart = new Cart;
$cart->addItem('Widget', 1000, 2); // 2 x $10.00 = 2000 cents
$receipt = (new Receipt)->build($cart);

check($receipt['subtotalCents'] === 2000, 'no-code subtotal is 2000 cents for 2x $10.00 widget');
check($receipt['taxCents'] === 160, 'no-code 8% tax on 2000 cents subtotal is 160 cents');
check($receipt['totalCents'] === 2160, 'no-code total is subtotal + tax = 2160 cents');

// --- (b) A valid discount code reduces the pre-tax subtotal by its percent
// before 8% tax is applied, and the receipt total reflects it. Must FAIL
// right now: DiscountCode.php does not exist and Receipt never discounts. ---

if (! class_exists('DiscountCode')) {
    echo "FAIL: DiscountCode class does not exist yet (src/DiscountCode.php missing) — expected before the M1 task is done\n";
    $failures++;
} else {
    $discountedCart = new Cart;
    $discountedCart->addItem('Widget', 1000, 2); // 2000 cents subtotal
    $discountedCart->applyDiscountCode(new DiscountCode('SAVE10', 10)); // 10% off

    $discountedReceipt = (new Receipt)->build($discountedCart);

    // 2000 - 10% = 1800 taxable; 8% of 1800 = 144; total = 1944.
    check(($discountedReceipt['discountCents'] ?? null) === 200, 'SAVE10 discounts 200 cents off a 2000-cent subtotal');
    check($discountedReceipt['taxCents'] === 144, '8% tax applies to the post-discount 1800 cents, not the full 2000');
    check($discountedReceipt['totalCents'] === 1944, 'discounted total is 1944 cents');
}

// --- (c) An out-of-range percent throws InvalidArgumentException. ---

if (! class_exists('DiscountCode')) {
    echo "FAIL: DiscountCode class does not exist yet — cannot check out-of-range validation\n";
    $failures++;
} else {
    foreach ([-5, 150] as $badPercent) {
        try {
            new DiscountCode('BAD', $badPercent);
            echo "FAIL: DiscountCode should reject an out-of-range percent of {$badPercent}\n";
            $failures++;
        } catch (InvalidArgumentException) {
            echo "PASS: DiscountCode rejects an out-of-range percent of {$badPercent}\n";
        }
    }
}

echo "\n".($failures === 0 ? 'All checks passed.' : "{$failures} check(s) failed.")."\n";

exit($failures === 0 ? 0 : 1);
