# Phase 7.2 — Baseline Audit

This is a separate audit artifact. The dated manifest was read only and not overwritten.

## Manifest verification

- User-requested path `C:\WABiz\CURRENT-STATE-BASELINE-2026-10-07.sha256` was not present.
- The existing manifest found and checked is `C:\WABiz\wabiz\CURRENT-STATE-BASELINE-2026-10-07.sha256`.
- Format: 196 valid SHA-256 entries; 0 malformed lines.
- Results: 164 matched, 32 mismatched, 0 missing, 0 unreadable.
- No file was restored based on hash mismatch.
- This manifest covers the enumerated Flutter/Laravel files; new job/migration files absent from its 196 entries do not appear as manifest mismatches.

## Attribution and per-file assessment

- The earlier report stated 29 mismatches; the unchanged 196-entry manifest currently yields 32.
- Search of WABiz project files and available Codex attachment files found no prior per-file mismatch list, prior manifest copy, or audit report that records the 29 individual files.
- The three additional mismatches therefore cannot be identified with certainty. Current source references, tests, and migrations establish feature relevance and impact risk only; they do not prove when or why a hash changed.
- Historical pre-Pint baseline remains unavailable.
- Risk labels below reflect the file's current role. They are not an assertion of historical cause.

| File | Expected SHA-256 | Actual SHA-256 | Risk | Current code/test evidence |
|---|---|---|---|---|
| `wabiz-backend/app/Http/Controllers/Api/AiAssistantSettingsController.php` | `5d0b55317137fe57256f681c436644d8a7700d703f35f51ed3662ea758558aa2` | `02d97778709d0013b1b3f5d568de5dbedf791f946824d930120e4814bb616f89` | High | API/auth/provider boundary; inspect route/controller/request and related feature tests. |
| `wabiz-backend/app/Http/Controllers/Api/ConversationController.php` | `a76e4e058b1ca3e098f82b78cbb77f53ca4043c56bcfdec4721fca15cf0a927b` | `a8a457ce1113801771fb03a3ca494415ab15a089ebb99a4f0c0f104cb34a0a1f` | High | API/auth/provider boundary; inspect route/controller/request and related feature tests. |
| `wabiz-backend/app/Http/Controllers/Api/CsMemberController.php` | `51619d891a91488fea5a96616903b9bd948a77cc3e7d70b20996ec6e562fe15e` | `54f727aabfb69a6aca179213459d85abbe47ccfd2101a3e265ef842d32461272` | High | API/auth/provider boundary; inspect route/controller/request and related feature tests. |
| `wabiz-backend/app/Http/Controllers/Api/WhatsAppWebhookController.php` | `62448d4d94e618dac4686d60fd0fbf4dbe3f2b9d61baa651c4e6cc650fd6b0fd` | `c4877da4932651709f000b4678f24c2b66af26d842d9b061e71183b39610a96c` | High | API/auth/provider boundary; inspect route/controller/request and related feature tests. |
| `wabiz-backend/app/Http/Requests/Api/ListConversationsRequest.php` | `20939c156b1b6fb86dd260e4a79ce34d8d1bcf3d10fb3b26bbbbb65e7aa0f656` | `03989f508c269b899722ad4bb2a5a79ea4b89030a0b397a17bd8afbf9aa5bfed` | High | API/auth/provider boundary; inspect route/controller/request and related feature tests. |
| `wabiz-backend/app/Http/Requests/Api/StoreConversationMessageRequest.php` | `1ba3a5ca7635a74619442ba190c31a9661319c535a29ec410e8b865cb280f404` | `f6dd7266f0bb1662ce709cfb3d4432cd5b871ce87b14cdc392ee65c88e1ee3b0` | High | API/auth/provider boundary; inspect route/controller/request and related feature tests. |
| `wabiz-backend/app/Http/Requests/Api/StoreCsMemberRequest.php` | `99ba7f1f92901ea0600b79eb834c6fec68eacee94d1385987632a795314398b0` | `342f4676de482d03d0d2a85b6d66e413702e068477301b62a0df6f2d1d6eff0f` | High | API/auth/provider boundary; inspect route/controller/request and related feature tests. |
| `wabiz-backend/app/Http/Requests/Api/UpdateAiAssistantSettingsRequest.php` | `a61184784518a19164e658e6a5820c3b30c5d23ca5d8a66ac1ee01f4b85f29dd` | `7b7bcead6725f58da53377ea3461fa5975eadf459ab5c478033854e78dbb7a91` | High | API/auth/provider boundary; inspect route/controller/request and related feature tests. |
| `wabiz-backend/app/Http/Requests/Api/UpdateCsMemberRequest.php` | `22882c95f9e1c4f53f805ee3cce93db5e4e766b81aef17a6552b6d8a459367e7` | `6cf8e3da710570cd5a7b2e74c55d98e707165d48cdbe5a9b169414dbcdb585df` | High | API/auth/provider boundary; inspect route/controller/request and related feature tests. |
| `wabiz-backend/app/Models/AiAssistantSetting.php` | `242b75b146b64d4c2b0853dda79fce07116fe55186cb1ec42953f87d07c874a9` | `ec177cb46f3d7dc2b0a38b74b09cd578f121edd822ae080debfb401c1d43e6ea` | High | AI context/decision/settings path; inspect AI services and settings tests. |
| `wabiz-backend/app/Models/Conversation.php` | `5f61ef864f38aac39c1ec8011eea49427113b7c2ccf97cf0d70f0d341c1f037f` | `ca78b13cb5caaf9dccac39ff9f849d89d6f7569637ee5d8367051781e93faa7e` | Medium | Persistence/relationship surface; inspect model and migration references. |
| `wabiz-backend/app/Services/AI/AiContextBuilder.php` | `f14116395da91353955d943c154774ac7b9dd6f4d2875705b8c4ac94e3f899d8` | `427e312c0bd95a113e8e6c8c3b4c17c09446a3c3617d7d51a7aebf14fd968e95` | High | AI context/decision/settings path; inspect AI services and settings tests. |
| `wabiz-backend/app/Services/AI/AiMessageProcessor.php` | `6baab07a1bfe16e1a7afb5f436178e389a952f360acb8115a8ce3a13c2f10aad` | `a0eff6593b4d14c507af0cd5541fc98361d66a855c97fd23f5b9f3e3b6e0bf72` | High | AI context/decision/settings path; inspect AI services and settings tests. |
| `wabiz-backend/app/Services/AI/ConversationHandoffService.php` | `84507b1ff09368d85cddde2e89708c3ab3dd9c020ed691955c4500b84403305a` | `4580387659c3c14efe4ef829f4ab950aea6c232c337e53b6ee1a038868d4950d` | High | AI context/decision/settings path; inspect AI services and settings tests. |
| `wabiz-backend/app/Services/AI/DeterministicAiOrchestrator.php` | `9890074bfaa55e3c6da546c1f8d860ca9cf930ed6ff1ceee885f701f51174ac7` | `c9cc44a39b67d87e551771b968b9ca948e64775f8149dbe5a2fac38081b36f43` | High | AI context/decision/settings path; inspect AI services and settings tests. |
| `wabiz-backend/app/Services/AI/RegistrationHandoffResolver.php` | `4fa5b4bd0b3d621aa40b6251c8cd3ecba237262fbdd87640462c058897a32346` | `34e01c73c93ed6219667d6027cc373bdf8d5813c1a70a9f6ce51dc812142b241` | High | AI context/decision/settings path; inspect AI services and settings tests. |
| `wabiz-backend/app/Services/WhatsApp/WhatsAppMessageSender.php` | `e293ace2342288cf7d8b5ccc4d2e2d23b447bd6bdaacae53b565a2892b6211ce` | `0a87e022b1dca24edeeece17d9dcc703d88ab98c1d20f8ae673152dc6258cba3` | High | API/auth/provider boundary; inspect route/controller/request and related feature tests. |
| `wabiz-backend/routes/api.php` | `05dd315a89b8c68e9eab184b3a5e9aee7bea6266ce57f5423f1ca8eb2d1720cd` | `129c02ac980643c27b4dbbfb40a40a3b3dac269e026a6fffa3ceedb7af12ecf9` | High | API/auth/provider boundary; inspect route/controller/request and related feature tests. |
| `wabiz-backend/tests/Feature/AiAssistantSettingsApiTest.php` | `a01a867d6a0d48d25566c40cbd16a345d4c34dcc4e6b35f8d91c9c4be5ce608a` | `77c92eaf23e9579207c80335e36097d8d19f7e0532c11e9eb15038c660a0654f` | High | AI context/decision/settings path; inspect AI services and settings tests. |
| `wabiz-backend/tests/Feature/AiMessageProcessorTest.php` | `55e4b5056cf662fd60ef62ece74aa1980000ca74a37209a5d74ae25f274fff3a` | `4d9be8da23d1c961d1d1519aa21bc0168a37975e1e771fc818e0625e52bb161f` | Low | Regression coverage; associated tests exercise the feature. |
| `wabiz-backend/tests/Feature/AiOrchestratorTest.php` | `9020c77bb068ff1636f1721650db3c9d90cad4eac68d94eb549c9804bcc85051` | `7d2a85389b386b9c463ffe42e0cc77b1ba4ec4a164992723884285178570b418` | Low | Regression coverage; associated tests exercise the feature. |
| `wabiz-backend/tests/Feature/ConversationApiTest.php` | `cdac00785b4295055e268c6d5c811d149ae60d605bf63f5d9f8ac650618c537e` | `75c53268d0d7e00a6b34caf701ba386ce8dc65c115baa5ed45cbae577f4c5fec` | Low | Regression coverage; associated tests exercise the feature. |
| `wabiz-backend/tests/Feature/ConversationHandoffServiceTest.php` | `53136026daff2547891d819699260e0a74ed29a90337e99ca7ada91b10d3c324` | `668d9141cdb924f440c0a8efcefca5cb289cd57462c7aea40a6cec4c97195c99` | Low | Regression coverage; associated tests exercise the feature. |
| `wabiz-backend/tests/Feature/CsMemberApiTest.php` | `dce87f1c83361df6542714f1214f602195ec340338d1d7e254e915469975450e` | `56cca0895485ad167df7a6986999daf0cecab68c523141cb2f39e097d95c0388` | Low | Regression coverage; associated tests exercise the feature. |
| `wabiz-backend/tests/Feature/WhatsAppOutboundApiTest.php` | `e1c2b126ff8fbdcee8fbe2606113042f0310c39bfe42b0deafc17fc3cafa4bc7` | `3d2869b37a655075fde028fba082f4c6ab651a4e17c609e3ccaf03f64d83ed5a` | Low | Regression coverage; associated tests exercise the feature. |
| `wabiz-backend/tests/Feature/WhatsAppWebhookTest.php` | `36c8b1015f8a61803c1b3bf943d41110d3184c143c17ae82b5657a3830622c77` | `ed52d08b51aed852c310ee05bfa087ee63d9faadb796a06f4a86cf5e8e9b2151` | Low | Regression coverage; associated tests exercise the feature. |
| `wabiz/lib/features/dashboard/presentation/pages/dashboard_page.dart` | `f289994e4ddcedfe019f53138f050b0e8e2417bf36ce16312d8c6346702e7f91` | `d702a3265acf0ebe475e3549585128fb3b606a7da09533a9e9d339d26661eced` | Medium | Flutter dashboard/Inbox path; related API/widget tests exist. |
| `wabiz/lib/features/inbox/data/conversation_api_service.dart` | `d7156dd9bb756b3f53daccaf4d579580193cd48c016c2c0383ff21098a176229` | `f8dd3e2ad050650bb8d0e95c6cdcc1eff852c17b6523b68c768c76452e75e974` | Medium | Flutter dashboard/Inbox path; related API/widget tests exist. |
| `wabiz/lib/features/inbox/presentation/pages/conversation_detail_page.dart` | `2975b60a4079d0ddbf712620f471a7b0d36407eeb9f2e938c7d4106bac786394` | `48c32f60dd9f8d58e0092e2c192d13c8053c0cd2d90431b2f177bb8580dc63dc` | Medium | Flutter dashboard/Inbox path; related API/widget tests exist. |
| `wabiz/lib/features/inbox/presentation/pages/inbox_page.dart` | `6c7fabb5ab0f99335db52ffaa61c13f58747f97670102e309624a10bf982846e` | `276b7e2a62ef0402e937775a90019917005e3935c3adc8ed6906774a49f525e7` | Medium | Flutter dashboard/Inbox path; related API/widget tests exist. |
| `wabiz/test/features/inbox/conversation_api_service_test.dart` | `9542a54a8910dbc1ab8c39ad3c678a7c718151a493b5fcd7764ee0124fb8d3a3` | `5b5dd2abeb7cee17d0bbc540cbaea2015c408bb20994f7dc959df4aec759cedc` | Medium | Flutter dashboard/Inbox path; related API/widget tests exist. |
| `wabiz/test/features/inbox/inbox_page_test.dart` | `6beea15576a0563f993d90f9e16a54af909334d516e07b01dc5752e547db34ec` | `d9e3504a7afbb6cf3c8679a2087089a949e853c6ab83a3a535ef1c09c247b4fb` | Medium | Flutter dashboard/Inbox path; related API/widget tests exist. |

## Delivery state and recovery

- AI delivery lifecycle: `pending → sending → sent`, or `failed` for a known failure, or `unknown` for an ambiguous outcome. Suppression is represented as `failed` with `metadata.delivery_state=suppressed` and a reason.
- A new owner/admin operation action marks an AI message `sending` for over five minutes as `unknown`; it does not requeue or call a provider.
- The action locks the organization-scoped conversation and message, validates AI outbound identity and stale state, and records previous/new status, actor ID, reason, timestamp, conversation state, both generations, threshold, and `retry_queued=false` in existing message metadata.
- Repeated action is rejected once the state is `unknown`; unauthorized and cross-tenant requests are rejected. Tests assert no AI send job is queued.
- `unknown` is not automatically retried. Provider timeout or stale `sending` is not proof of non-delivery. The operator must inspect provider-side delivery evidence; this endpoint only records uncertainty. There is no supported AI retry operation for `unknown` in this phase.
- Handoff notification retry remains a separate explicit operation and was not changed.
- No Phase 7.2 migration was added. The existing delivery-state migration is reported `Ran` on the configured test database; no production migration was run. A fresh-database migration rehearsal was not performed in this phase.

## Phase 7.2 files changed

- `wabiz-backend/app/Http/Controllers/Api/ConversationController.php` — authorized, tenant-scoped stale AI-send reconciliation.
- `wabiz-backend/routes/api.php` — route to the existing conversation controller.
- `wabiz-backend/tests/Feature/ConversationApiTest.php` — stale/non-stale state, tenant, authorization, repeat-action, audit metadata, and no-queue checks.
- `wabiz/PHASE-7.2-BASELINE-AUDIT.md` — this report only; the baseline manifest is unchanged.

## Runtime safety

- No QR scan, pairing, WhatsApp login, or real message was performed.
- Tests use fake providers or assert that no job is queued; no real provider delivery was used.
- WPPConnect gateway source and security boundary were not changed.

## Remaining limitations

- The three mismatch additions from 29 to 32 remain unidentifiable without the earlier per-file snapshot or equivalent history.
- AI ambiguous outcomes remain `unknown`; no retry is available because the provider does not provide a proven safe idempotent recovery path.
- A provider call can hold database locks while it is in flight; a slow provider can delay concurrent state changes.
