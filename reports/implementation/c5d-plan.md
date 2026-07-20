# Plan: PLAYGROUND_C5D_FORUM_REPORTING_MODERATION_AND_COMMUNITY_HARDENING

This plan outlines the architecture, database schema, use cases, API, security matrix, and testing strategies for delivering a genuinely complete, production-grade community and moderation module.

## 1. Bounded Contexts and Module Structure

The Playground application consists of several bounded contexts. We are hardening and expanding the `Community` bounded context under `src/Community`:

```
src/Community
├── Domain/
│   ├── Review/ (Hardened and structured)
│   │   ├── Review.php
│   │   ├── ReviewStatus.php
│   │   └── ReviewRepository.php
│   ├── PlaceDiscussion/ (Hardened and structured)
│   │   ├── PlaceComment.php
│   │   ├── PlaceCommentStatus.php
│   │   └── PlaceCommentRepository.php
│   ├── Forum/ (NEW - Independent public forum)
│   │   ├── ForumCategory.php
│   │   ├── ForumThread.php
│   │   ├── ForumThreadStatus.php
│   │   ├── ForumPost.php
│   │   ├── ForumPostStatus.php
│   │   ├── ForumCategoryRepository.php
│   │   ├── ForumThreadRepository.php
│   │   └── ForumPostRepository.php
│   └── Moderation/ (NEW - reporting and moderation aggregate)
│       ├── ContentReport.php
│       ├── ReportStatus.php
│       ├── ReportReason.php
│       ├── TargetType.php
│       ├── ContentReportRepository.php
│       ├── ModerationActionRecord.php
│       ├── ModerationActionType.php
│       └── ModerationActionRepository.php
├── Application/
│   ├── Port/ (Abstract lookup ports)
│   │   ├── ActiveCommunityUserLookup.php
│   │   ├── PublicAuthorProfileLookup.php
│   │   └── PublishedPlaceLookup.php
│   └── UseCase/ (Modular application use cases)
│       ├── ListReviews.php
│       ├── CreateReview.php
│       ├── UpdateReview.php
│       ├── DeleteReview.php
│       ├── ListComments.php
│       ├── CreateComment.php
│       ├── CreateReply.php
│       ├── UpdateComment.php
│       ├── DeleteComment.php
│       ├── ListForumCategories.php
│       ├── ListCategoryThreads.php
│       ├── GetForumThread.php
│       ├── ListForumPosts.php
│       ├── CreateForumThread.php
│       ├── EditOwnForumThread.php
│       ├── DeleteOwnForumThread.php
│       ├── CreateForumPost.php
│       ├── EditOwnForumPost.php
│       ├── DeleteOwnForumPost.php
│       ├── GetCommunityFeed.php
│       ├── ReportContent.php
│       ├── ListModerationQueue.php
│       └── ModerateContent.php
├── Infrastructure/
│   ├── Port/
│   │   ├── ActiveCommunityUserDbalLookup.php
│   │   ├── PublicAuthorProfileDbalLookup.php
│   │   └── PublishedPlaceDbalLookup.php
│   ├── Review/
│   │   ├── DbalReviewRepository.php
│   │   └── DbalRatingSummaryLookup.php
│   ├── PlaceDiscussion/
│   │   └── DbalPlaceCommentRepository.php
│   ├── Forum/
│   │   ├── DbalForumCategoryRepository.php
│   │   ├── DbalForumThreadRepository.php
│   │   └── DbalForumPostRepository.php
│   └── Moderation/
│       ├── DbalContentReportRepository.php
│       └── DbalModerationActionRepository.php
└── UI/
    └── Http/ (Thin request/response controllers only)
        ├── ReviewQueryController.php
        ├── ReviewCommandController.php
        ├── PlaceDiscussionQueryController.php
        ├── PlaceDiscussionCommandController.php
        ├── ForumQueryController.php
        ├── ForumCommandController.php
        ├── ContentReportController.php
        ├── ModerationController.php
        └── ControllerHelperTrait.php
```

---

## 2. Immediate repairs (Hardening & Bugfixes)

### 2.1 Hidden-Content Disclosure (Section 2.1)
- **Problem**: Stale implementation might reveal draft, hidden, or moderated comment bodies and author profiles to the public.
- **Solution**: 
  - Change repository methods to explicitly exclude `HIDDEN` and `REMOVED_BY_MODERATOR` from standard public read-queries.
  - Implement tombstone rendering for `DELETED_BY_AUTHOR` status directly in the Use Cases / Projections. Original body and deleted author profiles are never returned.
  - Add integration tests verifying that hidden content and deleted authors' metadata do not leak in public endpoints.

### 2.2 Public Place Validation (Section 2.2)
- **Problem**: Unverified place IDs might expose community content associated with unreleased or unpublished places.
- **Solution**: 
  - Call `PublishedPlaceLookup::isPublished` at the start of standard queries and commands.
  - Throw a non-enumerating `ApiException(404, MISSING_PUBLIC_RESOURCE)` for drafts, unarchived, missing, or private places.

### 2.3 Reply Lifecycle (Section 2.3)
- **Problem**: Nested nested replies (replies to replies) must be blocked; replies to deleted parents should render beneath a tombstone.
- **Solution**: 
  - Enforce in `CreateReply` that the parent comment exists, has no parent itself (`parentId` is null), and status is currently `PUBLISHED`.
  - Block replies to replies with `COMMENT_REPLY_DEPTH_LIMIT` machine-readable code.
  - Replies beneath `DELETED_BY_AUTHOR` parents remain visible if they are public.

### 2.4 Pagination Hardening (Section 2.4)
- **Problem**: Flat pagination of parents and replies orphans children and produces duplicate entries.
- **Solution**: 
  - Implement Root-only cursor/keyset pagination (`created_at ASC, id ASC`).
  - Nested replies for each Root on the page are batch loaded in a single query and embedded hierarchically.
  - Pagination metadata includes root comment count, visible reply count, and next page cursor.

### 2.5 N+1 Query Elimination (Section 2.5)
- **Problem**: Serial `PublicAuthorProfileLookup::getProfile()` calls trigger N+1 queries.
- **Solution**: 
  - Add `getProfiles(array $userIds): array` batch port.
  - Perform exactly 1 SQL query with `IN (:ids)` inside the DBAL adapter.
  - Add query-count assertion in integration tests to enforce bounded query complexity.

### 2.6 Optimistic Concurrency and Duplicate insertion Hardening (Section 2.6)
- **Problem**: Using `ReflectionProperty` to advance versions is anemic; active review insert races can leak internal SQL details.
- **Solution**: 
  - Implement a clean aggregate method `advanceVersion()` on domain models.
  - Translate write conflicts to HTTP 409 `CONCURRENCY_CONFLICT` Problem Details.
  - Handle duplicate review insert unique constraint races as deterministic HTTP 409 `REVIEW_ALREADY_EXISTS`.

### 2.7 Environment Configurable Rate Limiting (Section 2.7)
- **Problem**: In-process limits must be configurable and testable without slow sleeps.
- **Solution**: 
  - Create separate rate limiters for reviews, comments, threads, posts, reports, and moderation writes.
  - Enforce them using the authenticated user ID as primary key and trusted IP fallback.
  - Inject custom test environment rate limits (`LIMIT_WRITE=5`) so 429 exceptions can be deterministically tested.

---

## 3. Database Schema and Performance

A corrective forward migration (`Version20260719183000.php`) will add the following indexes:
- `reviews(place_id, status, created_at DESC, id DESC)`
- `reviews(place_id, status, rating)`
- `place_comments(place_id, status, parent_id, created_at ASC, id ASC)`
- `place_comments(parent_id, status, created_at ASC, id ASC)`
- `forum_threads(category_id, status, pinned_at DESC, last_activity_at DESC, id DESC)`
- `forum_posts(thread_id, status, created_at ASC, id ASC)`
- `content_reports(status, created_at DESC, id DESC)`
- `content_reports(target_type, target_id)`
- `moderation_actions(target_type, target_id, created_at DESC, id DESC)`

---

## 4. Testing Matrix and Acceptance Gates

### 4.1 Backend Domain & Integration Tests
- Unit test all lifecycle transitions (edit, softDelete, hide, resolve, lock, pin) for:
  - `Review`
  - `PlaceComment`
  - `ForumCategory`
  - `ForumThread`
  - `ForumPost`
  - `ContentReport`
  - `ModerationActionRecord`
- Verify the SQL query plan, query count, and cursor-pagination consistency on realistic fixture volumes.

### 4.2 HTTP Integration Tests
- Fully cover authorization matrix (Anon, Alice, Bob, Inactive, Moderator, Admin).
- Verify:
  - CSRF protection.
  - Rate limiting (returns 429, Retry-After, Problem Details).
  - Optimistic concurrency (409 CONCURRENCY_CONFLICT).
  - Review duplicates (409 REVIEW_ALREADY_EXISTS).
  - Non-enumeration of private resources (404).

---

## 5. Implementation Steps & Phased Rollout

1. **Phase 1: Preflight & Environment Setup** (COMPLETED)
   - Preflight report generated.
   - Corrective migrations verified.
2. **Phase 2: Bounded Context Hardening** (COMPLETED)
   - Moved `ApiException` to SharedApplication.
   - Refactored `DbalReviewRepository` and `DbalPlaceCommentRepository` concurrency models.
   - Implemented `ListComments` root-only cursor pagination.
   - Batch profile loading (N+1 removal) implemented in DBAL lookup.
3. **Phase 3: Independent Forum Domain & Use Cases** (COMPLETED)
   - Forum domain models and repositories fully implemented.
   - Use Cases (GetCommunityFeed, ListCategoryThreads, etc.) completed.
4. **Phase 4: Reporting & Moderation Aggregate** (COMPLETED)
   - Content reports and moderation audit log models, repositories, and use cases completed.
5. **Phase 5: Controller Refactoring** (COMPLETED)
   - Split `CommunityController` into 8 clean controllers.
   - Configured rate limiters and autowired aliases.
   - Documented all 14 new endpoints in `HealthOpenApiFactory`.
   - Verified 100% PHP compilation and linter.
6. **Phase 6: OpenAPI & API Client Generation** (COMPLETED)
   - Exported openapi.json from dev container.
   - Generated the updated client with `openapi-ts`.
   - Verified 100% TypeScript compilation of generated packages.
7. **Phase 7: Frontend Modularization & UI Hardening** (PENDING)
   - Refactor Remix frontend routes and components to consume updated client types.
   - Integrate accessible shadcn/ui dialogs and inline validation.
8. **Phase 8: E2E Playwright Journeys & Certification** (PENDING)
   - Run full local test suite and Playwright specifications.
   - Persist findings and final handoff reports.
