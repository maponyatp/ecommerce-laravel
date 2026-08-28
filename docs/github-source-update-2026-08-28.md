# Maponya source-only update

This branch preserves the shop source from local commit `4fdc1aa`, before an unfinished merge. The original checkout and its 81 conflicting files are untouched. The branch is for review, not automatic replacement of `main`: Maponya's current main also contains changes not included in this shop snapshot.

Operational `work/` files, database copies, deployment archives, logs and generated frontend bundles are excluded. Build frontend assets from the checked-in source. Environment examples contain development/test placeholders, not merchant credentials. The original license attribution is retained.

The snapshot is published as a new commit on Maponya's existing public history, without publishing the local-only commit ancestry containing operational artifacts. No force-push, production deployment or old-upstream synchronization is performed. The old upstream remote has been removed from the working repository; `origin` is the default push destination.

This snapshot includes previously implemented but undeployed theme, refund-recording and staff-security work. Their presence here does not establish that these workflows are production-ready. The payment-options release documents the current limit: PayFast, Peach and Ozow credential forms exist, but checkout integrations remain unfinished.
