import { afterEach, beforeEach, describe, expect, test, vi } from "vitest"
import { loader as feedLoader } from "./community/feed"
import { loader as categoriesLoader } from "./community/forum-categories"
import { loader as threadsLoader } from "./community/forum-threads"
import { loader as threadDetailLoader } from "./community/forum-thread-detail"

describe("Community SSR loaders and HTML content", () => {
  beforeEach(() => {
    vi.stubEnv("API_BASE_URL", "http://api")
  })

  afterEach(() => {
    vi.restoreAllMocks()
    vi.unstubAllEnvs()
  })

  test("feed loader fetches feed items for SSR", async () => {
    vi.spyOn(global, "fetch").mockResolvedValue(
      new Response(
        JSON.stringify({
          items: [
            {
              id: "feed-1",
              type: "forum_thread",
              activityAt: "2026-08-19T12:00:00Z",
              author: { id: "user-1", displayName: "Alice", initials: "A" },
              title: "Dyskusja o nowym placu zabaw",
              excerpt: "Świetne miejsce dla dzieci w każdym wieku.",
              sourceId: "thread-1",
            },
          ],
          pagination: { nextCursor: null, hasNextPage: false },
        }),
        { status: 200, headers: { "Content-Type": "application/json" } }
      )
    )

    const result = await feedLoader()
    expect(result.feedData.items).toHaveLength(1)
    expect(result.feedData.items[0].title).toBe("Dyskusja o nowym placu zabaw")

    const fetchReq = vi.mocked(fetch).mock.calls[0][0] as Request
    expect(fetchReq.url).toBe("http://api/api/v1/community/feed?limit=10")
  })

  test("categories loader fetches forum categories for SSR", async () => {
    vi.spyOn(global, "fetch").mockResolvedValue(
      new Response(
        JSON.stringify([
          { id: "cat-1", slug: "bawialnie", name: "Bawialnie i sale zabaw", description: "Miejsca do zabawy pod dachem." },
        ]),
        { status: 200, headers: { "Content-Type": "application/json" } }
      )
    )

    const result = await categoriesLoader()
    expect(result.categoriesData).toHaveLength(1)
    expect(result.categoriesData[0].name).toBe("Bawialnie i sale zabaw")

    const fetchReq = vi.mocked(fetch).mock.calls[0][0] as Request
    expect(fetchReq.url).toBe("http://api/api/v1/forum/categories")
  })

  test("threads loader fetches category threads for SSR", async () => {
    vi.spyOn(global, "fetch").mockResolvedValue(
      new Response(
        JSON.stringify({
          category: { id: "cat-1", slug: "bawialnie", name: "Bawialnie", description: "Opis" },
          items: [
            { id: "thr-1", title: "Najlepsza bawialnia w Warszawie", authorId: "u1", author: { id: "u1", displayName: "Bob", initials: "B" }, createdAt: "2026-08-19T12:00:00Z" },
          ],
          pagination: { nextCursor: null, hasNextPage: false },
        }),
        { status: 200, headers: { "Content-Type": "application/json" } }
      )
    )

    const result = await threadsLoader({ params: { categorySlug: "bawialnie" }, request: new Request("http://localhost/forum/bawialnie") } as any)
    expect(result.data.category.name).toBe("Bawialnie")
    expect(result.data.items[0].title).toBe("Najlepsza bawialnia w Warszawie")

    const fetchReq = vi.mocked(fetch).mock.calls[0][0] as Request
    expect(fetchReq.url).toBe("http://api/api/v1/forum/categories/bawialnie/threads?limit=10")
  })

  test("thread detail loader fetches thread and initial posts concurrently for SSR", async () => {
    vi.spyOn(global, "fetch").mockImplementation(async (req) => {
      const urlStr = req instanceof Request ? req.url : String(req)
      if (urlStr.includes("/posts")) {
        return new Response(
          JSON.stringify({
            items: [
              { id: "post-1", body: "Pierwszy post w wątku.", isInitial: true, authorId: "u1", author: { id: "u1", displayName: "Alice", initials: "A" }, createdAt: "2026-08-19T12:00:00Z" },
            ],
            pagination: { nextCursor: null, hasNextPage: false },
          }),
          { status: 200, headers: { "Content-Type": "application/json" } }
        )
      }
      return new Response(
        JSON.stringify({
          id: "thr-1",
          title: "Wątek szczegółowy SSR",
          authorId: "u1",
          author: { id: "u1", displayName: "Alice", initials: "A" },
          createdAt: "2026-08-19T12:00:00Z",
          status: "PUBLISHED",
        }),
        { status: 200, headers: { "Content-Type": "application/json" } }
      )
    })

    const result = await threadDetailLoader({ params: { threadId: "thr-1" }, request: new Request("http://localhost/forum/watek/thr-1") } as any)
    expect(result.data.thread.title).toBe("Wątek szczegółowy SSR")
    expect(result.data.posts.items[0].body).toBe("Pierwszy post w wątku.")
  })
})
