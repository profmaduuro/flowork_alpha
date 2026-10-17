# Flowork pilot plan

Purpose: prove on one real floor, alongside the current way of working, that Flowork's figures can be trusted before it is sold to other floors.

## Scope

- **Floor:** Vision Leaf Tobacco (floor 1), on the floor PC GENIUSENGINEER.
- **Length:** at least 10 consecutive sale days (about two weeks), longer if any difference is still open.
- **Parallel run:** the current process (manual tickets, sales sheets and payment register) stays the official record. Flowork is used for the same deliveries; nothing is paid from Flowork's figures alone during the pilot.
- **Roles:** floor manager (runs the sale in Flowork), accounts (daily reconciliation and sign-off), owner (decides on any difference and on go/no-go).

## Before day 1 (all must be done)

- [ ] Apache restarted as administrator, so HTTPS and the new certificate are live; every device used on the floor trusts the Flowork Local CA (DEPLOYMENT.md, section 2).
- [ ] `sysadmin` and `bright` have set up 2FA; every user has their own account (no shared logins).
- [ ] `BACKUP_PASSPHRASE` and `BACKUP_COPY_DIR` set; the nightly task runs whether or not anyone is logged on; the passphrase is stored off the PC.
- [ ] Settings checked for the season: stop-order mode and RBZ split (each new delivery records the rule in force when it is first calculated — see "Decisions" below), charges, exchange-rate routine.
- [ ] The July and June payment differences have an agreed treatment (recovery, offset or write-off) recorded by accounts.
- [ ] Smoke and money tests pass apart from the known payment differences:
  `C:\xampp\php\php.exe tests\smoke.php` and `C:\xampp\php\php.exe tests\money.php`

## Every sale day

1. Run the sale in Flowork as normal (booking, tickets, selling, balancing, stop orders, payments).
2. After balancing, accounts runs the daily report and saves the CSV:
   ```
   C:\xampp\php\php.exe C:\xampp\htdocs\flowork_alpha\maintenance\daily_recon.php --date=YYYY-MM-DD --floor=1 --csv=C:\pilot\recon_YYYY-MM-DD.csv
   ```
3. Compare, delivery by delivery, with the manual records: bales, mass, gross, each charge, stop orders per creditor, net USD and ZiG, and the amount paid.
4. Every difference goes in the log below the same day with its cause. A difference is **explained** only when its cause is found and either fixed or accepted by the owner in writing.
5. Accounts signs the day off (initials and date on the CSV, or a line in the log).

The report's own checks must say **OK**: gross matches the bales, one net and a recorded rule per delivery, every payment equals the stored net.

## Weekly

- Restore the latest backup into a scratch database and log in to a copy (RELEASE_CHECKLIST.md, section 5). Record date and duration.
- Review the error log `C:\xampp\apache\logs\error.log` for new PHP errors from `flowork_alpha`.
- Review the audit log for price, rate, stop-order and payment changes made outside the normal flow.

## Exit criteria (go to market)

All of these, over at least 10 consecutive sale days:

- zero unexplained differences between Flowork and the manual records;
- every daily report check OK (no payment different from its stored net);
- no lost or duplicated delivery, payment or stop-order record;
- one successful backup restore during the pilot;
- no unplanned downtime longer than 30 minutes on a sale day;
- floor staff can run a sale day without developer help.

If any criterion fails, fix the cause, then restart the 10-day count.

## Stop / roll back

Stop using Flowork for the pilot (the manual process continues anyway) if figures cannot be explained within one working day, if data is lost, or if the system is unavailable for a sale. Restore from the last good backup if data was damaged (DEPLOYMENT.md and RELEASE_CHECKLIST.md).

## Decisions taken / open

- **Taken 2026-10-17:** rule A (stop orders after the RBZ split, 70/30) for the 8 July deliveries; all existing deliveries record rule A.
- **Open:** the Settings still say stop orders *before* the split and an 80/20 split, so new deliveries will be calculated that way. Confirm which rule applies for the rest of the season and set it in Settings before day 1.
- **Open:** stop order 158 (creditor 61631 Vision Leaf, $18,790.73, grower V108816) went from "processed" to "not processed" during the 8 July correction because nothing had been deducted from it; the next stop-order run for that grower will try to collect it. Confirm that is wanted.
- **Open:** the June payments were made before the 2% bank charge ($101.29 in total); decide whether the bank charge applied then.

## Difference log

| Date | Delivery / grower | What differed | Cause | Fixed / accepted by | Closed |
|---|---|---|---|---|---|
| | | | | | |
