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

2. Add a method Cart::applyDiscountCode(DiscountCode $code) and expose the
   resulting discount amount in cents, rounded to the nearest cent, as a
   percentage of the current subtotal.

3. Update Receipt::build() to subtract that discount from the subtotal
   BEFORE computing the flat 8% tax, and include the discount amount in the
   returned receipt data under the key `discountCents`. Tax and total must
   be computed on the post-discount amount, not the original subtotal.

Do not change the existing no-discount behavior: a cart with no code applied
must produce exactly the same subtotal/tax/total as before.
```

## Objective pass/fail criteria

Run these from the scratch copy `init.sh` printed (not from the paider repo):

1. **`php tests/run.php` exits 0.** This is the real correctness check —
   it already passes the no-discount case and currently fails the two
   discount-related checks on purpose (verified in the build report; run it
   yourself against the pristine copy to see the pre-task failure).
2. **`git add -A && git diff --stat --cached` (from inside the scratch copy,
   against the `fixture: pristine baseline` commit) touches at least 3
   files, including a new `src/DiscountCode.php`.** Plain `git diff --stat
   HEAD` will NOT show this — `DiscountCode.php` is untracked until staged,
   and an untracked file never appears in an unstaged diff, so `git add -A`
   first is required or a correct run reads as a failure. A single
   search-replace cannot satisfy this criterion once staged correctly: it
   requires a brand-new class plus a formula change that spans two other
   files (`Cart.php` gains state and a method, `Receipt.php` changes its
   arithmetic).
3. **No unrelated files changed.** The staged `git diff --stat --cached`
   from step 2 should show only `src/Cart.php`, `src/Receipt.php`, and the
   new `src/DiscountCode.php` (and optionally `tests/run.php` only if
   Paider chooses to add its own test, which is not required — the
   fixture's `tests/run.php` is the objective check and should not need
   editing to pass). `README.md` should be untouched.

All three must hold. If (1) passes but (2) or (3) don't, note it — a lucky
one-file hack that happens to pass the assertions is still a fail for this
rehearsal's purpose (watching a genuine multi-file edit).
