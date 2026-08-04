# M1 rehearsal task

Target: a scratch copy of `m1/fixture/` produced by `m1/fixture/init.sh` (see
`m1/RUNBOOK.md` — never point Paider at the fixture inside the paider repo
itself).

## Prompt to paste into `paider chat`, verbatim

```
This cart/receipt library has no discount support. Add one:

1. Add src/DiscountCode.php with a class DiscountCode. Its constructor takes
   a string $code and a float $percentOff. Throw InvalidArgumentException if
   $percentOff is <= 0 or > 100 (a code must give a real, non-zero discount
   and cannot exceed 100%).

2. Add a way for Cart to accept an optional DiscountCode (e.g. an
   applyDiscountCode(DiscountCode $code) method) and expose the resulting
   discount amount in cents, rounded to the nearest cent, as a percentage of
   the current subtotal.

3. Update Receipt::build() to subtract that discount from the subtotal
   BEFORE computing the flat 8% tax, and include the discount amount in the
   returned receipt data. Tax and total must be computed on the
   post-discount amount, not the original subtotal.

Do not change the existing no-discount behavior: a cart with no code applied
must produce exactly the same subtotal/tax/total as before.
```

## Objective pass/fail criteria

Run these from the scratch copy `init.sh` printed (not from the paider repo):

1. **`php tests/run.php` exits 0.** This is the real correctness check —
   it already passes the no-discount case and currently fails the two
   discount-related checks on purpose (verified in the build report; run it
   yourself against the pristine copy to see the pre-task failure).
2. **`git diff --stat` (against the fixture's `fixture: pristine baseline`
   commit, from inside the scratch copy) touches at least 3 files,
   including a new `src/DiscountCode.php`.** A single search-replace cannot
   satisfy this: it requires a brand-new class plus a formula change that
   spans two other files (`Cart.php` gains state and a method, `Receipt.php`
   changes its arithmetic), and `DiscountCode.php` does not exist until
   Paider creates it.
3. **No unrelated files changed.** `git diff --stat` should show only
   `src/Cart.php`, `src/Receipt.php`, and the new `src/DiscountCode.php` (and
   optionally `tests/run.php` only if Paider chooses to add its own test,
   which is not required — the fixture's `tests/run.php` is the objective
   check and should not need editing to pass). `README.md` should be
   untouched.

All three must hold. If (1) passes but (2) or (3) don't, note it — a lucky
one-file hack that happens to pass the assertions is still a fail for this
rehearsal's purpose (watching a genuine multi-file edit).
