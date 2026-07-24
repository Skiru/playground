import { cleanup, render, screen, fireEvent, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi, beforeEach } from "vitest";
import { MemoryRouter, Route, Routes } from "react-router";
import * as React from "react";
import { SessionProvider } from "~/lib/session-context";
import { LoginRequiredActionProvider } from "~/features/auth/LoginRequiredActionContext";
import { mapApiError } from "~/utils/error-mapper";

// Mock AppShell to isolate page components from outer headers, footers and layouts
vi.mock("~/components/layout/AppShell", () => {
  return {
    AppShell: ({ children }: { children: React.ReactNode }) => <div data-testid="mock-app-shell">{children}</div>
  };
});

vi.mock("~/features/auth/LoginDialog", () => ({
  LoginDialog: () => null,
}));

// Mock the generated API client
vi.mock("@family-places/api-client", () => {
  return {
    listCategoryThreads: vi.fn(),
    createForumThread: vi.fn(),
    getForumThread: vi.fn(),
    listForumPosts: vi.fn(),
    createForumPost: vi.fn(),
    getCommunityFeed: vi.fn(),
    listModerationQueue: vi.fn(),
    reportContent: vi.fn(),
    editOwnForumThread: vi.fn(),
    deleteOwnForumThread: vi.fn(),
    editOwnForumPost: vi.fn(),
    deleteOwnForumPost: vi.fn(),
  };
});

import ForumThreadsPage from "./community/forum-threads";
import ForumThreadDetailPage from "./community/forum-thread-detail";
import CommunityFeedPage from "./community/feed";
import ModeratorQueuePage from "./community/moderator-queue";
import { ReportContentDialog } from "~/components/community/ReportContentDialog";

import {
  listCategoryThreads,
  getForumThread,
  listForumPosts,
  getCommunityFeed,
  listModerationQueue,
  reportContent,
} from "@family-places/api-client";

describe("Community Frontend Vitest Suite", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    // Fix JSDOM missing scrollIntoView
    window.HTMLElement.prototype.scrollIntoView = vi.fn();
  });

  afterEach(() => {
    cleanup();
  });

  it("renders forum category and supports thread list loading with pagination", async () => {
    vi.mocked(listCategoryThreads).mockResolvedValue({
      response: new Response(null, { status: 200 }),
      data: {
        category: { id: "cat-1", slug: "warszawa", name: "Warszawa", description: "Forum dla Warszawy" },
        items: [
          { id: "thread-1", categoryId: "cat-1", authorId: "user-1", title: "Gdzie na weekend z dzieckiem?", status: "PUBLISHED", createdAt: "2026-07-20T12:00:00Z", author: { id: "user-1", displayName: "Jan", initials: "J" } }
        ],
        pagination: {
          nextCursor: "cursor-abc",
          hasNextPage: true,
        }
      }
    } as never);

    render(
        <MemoryRouter initialEntries={["/forum/warszawa"]}>
          <Routes>
            <Route path="/forum/:categorySlug" element={
              <SessionProvider initialSession={{ authenticated: true, user: { id: "user-1", displayName: "Jan", initials: "J", roles: ["ROLE_USER"] }, csrfToken: "csrf-abc" }}>
                <LoginRequiredActionProvider>
                  <ForumThreadsPage />
                </LoginRequiredActionProvider>
              </SessionProvider>
            } />
          </Routes>
        </MemoryRouter>
    );

    // Assert category details render
    await waitFor(() => {
      expect(screen.getAllByText("Warszawa").length).toBeGreaterThanOrEqual(1);
      expect(screen.getByText("Forum dla Warszawy")).toBeInTheDocument();
      expect(screen.getByText("Gdzie na weekend z dzieckiem?")).toBeInTheDocument();
      expect(screen.getByRole("button", { name: "Wczytaj więcej" })).toBeInTheDocument();
    });
  });

  it("handles thread locked state and tombstone post rendering", async () => {
    vi.mocked(getForumThread).mockResolvedValue({
      response: new Response(null, { status: 200 }),
      data: { id: "thread-1", categoryId: "cat-1", authorId: "user-1", title: "Locked Thread", status: "PUBLISHED", lockedAt: "2026-07-20T13:00:00Z", author: { id: "user-1", displayName: "Jan", initials: "J" } }
    } as never);

    vi.mocked(listForumPosts).mockResolvedValue({
      response: new Response(null, { status: 200 }),
      data: {
        items: [
          { id: "post-1", threadId: "thread-1", authorId: "user-2", body: "A reply", status: "DELETED_BY_AUTHOR", createdAt: "2026-07-20T12:05:00Z", author: { id: "user-2", displayName: "Anna", initials: "A" } }
        ],
        pagination: { nextCursor: null, hasNextPage: false }
      }
    } as never);

    render(
        <MemoryRouter initialEntries={["/forum/watek/thread-1"]}>
          <Routes>
            <Route path="/forum/watek/:threadId" element={
              <SessionProvider initialSession={{ authenticated: true, user: { id: "user-1", displayName: "Jan", initials: "J", roles: ["ROLE_USER"] }, csrfToken: "csrf-abc" }}>
                <LoginRequiredActionProvider>
                  <ForumThreadDetailPage />
                </LoginRequiredActionProvider>
              </SessionProvider>
            } />
          </Routes>
        </MemoryRouter>
    );

    // Assert locked notice and tombstone post
    await waitFor(() => {
      expect(screen.getByText("Wątek zablokowany")).toBeInTheDocument();
      expect(screen.getByText("Treść usunięta przez autora")).toBeInTheDocument();
    });
  });

  it("renders community feed and builds correct source links", async () => {
    vi.mocked(getCommunityFeed).mockResolvedValue({
      response: new Response(null, { status: 200 }),
      data: {
        items: [
          { id: "thread-1", type: "forum_thread", activityAt: "2026-07-20T12:00:00Z", author: { id: "user-1", displayName: "Jan", initials: "J" }, title: "Interesting Thread", excerpt: "Excerpt of thread", sourceId: "cat-1", placeSlug: null },
          { id: "post-2", type: "forum_post", activityAt: "2026-07-20T12:10:00Z", author: { id: "user-2", displayName: "Anna", initials: "A" }, title: null, excerpt: "Excerpt of post", sourceId: "thread-1", placeSlug: null }
        ],
        pagination: { nextCursor: null, hasNextPage: false }
      }
    } as never);

    render(
      <MemoryRouter>
        <SessionProvider initialSession={{ authenticated: false, user: null, csrfToken: null }}>
          <LoginRequiredActionProvider>
            <CommunityFeedPage />
          </LoginRequiredActionProvider>
        </SessionProvider>
      </MemoryRouter>
    );

    await waitFor(() => {
      expect(screen.getByText("Jan")).toBeInTheDocument();
      expect(screen.getByText("Anna")).toBeInTheDocument();
      expect(screen.getAllByText(/Przejdź do źródła/)).toHaveLength(2);
    });
  });

  it("renders moderator queue with correct status filters and cursor pagination", async () => {
    vi.mocked(listModerationQueue).mockResolvedValue({
      response: new Response(null, { status: 200 }),
      data: {
        items: [
          { id: "report-1", reporterId: "user-2", reporter: { id: "user-2", displayName: "Anna", initials: "A" }, targetType: "REVIEW", targetId: "rev-1", reason: "SPAM", details: "Spam details", status: "OPEN", createdAt: "2026-07-20T12:00:00Z", evidence: "Inappropriate review content" }
        ],
        pagination: { nextCursor: "cursor-123", hasNextPage: true, totalItems: 1 }
      }
    } as never);

    render(
      <MemoryRouter>
        <SessionProvider initialSession={{ authenticated: true, user: { id: "mod-1", roles: ["ROLE_MODERATOR"], displayName: "Moderator", initials: "M" }, csrfToken: "csrf-abc" }}>
          <LoginRequiredActionProvider>
            <ModeratorQueuePage />
          </LoginRequiredActionProvider>
        </SessionProvider>
      </MemoryRouter>
    );

    await waitFor(() => {
      expect(screen.getByText("Panel Moderatorów")).toBeInTheDocument();
      expect(screen.getByText(/Inappropriate review content/)).toBeInTheDocument();
      expect(screen.getByRole("button", { name: "Wczytaj więcej" })).toBeInTheDocument();
    });
  });

  it("handles reporting success and error flows (409 & 429)", async () => {
    vi.mocked(reportContent).mockResolvedValue({
      response: new Response(null, { status: 201 }),
      data: {}
    } as never);

    render(
      <SessionProvider initialSession={{ authenticated: true, user: { id: "user-1", roles: ["ROLE_USER"], displayName: "Jan", initials: "J" }, csrfToken: "csrf-abc" }}>
        <LoginRequiredActionProvider>
          <ReportContentDialog targetId="post-1" targetType="FORUM_POST" trigger={<button>Report</button>} />
        </LoginRequiredActionProvider>
      </SessionProvider>
    );

    fireEvent.click(screen.getByText("Report"));
    await expect(screen.getByText("Zgłoś naruszenie regulaminu")).toBeInTheDocument();
  });

  it("maps generated API client errors using Problem Details error mapper", () => {
    const errorDetails = {
      type: "https://familyplaces.example/problems/VALIDATION_FAILURE",
      title: "Validation failed",
      status: 400,
      detail: "Invalid content report request.",
      code: "VALIDATION_FAILURE"
    };

    const result = mapApiError(errorDetails);
    expect(result.status).toBe(400);
    expect(result.detail).toBe("Invalid content report request.");
    expect(result.code).toBe("VALIDATION_FAILURE");
  });
});
