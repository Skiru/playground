import { fireEvent, render, screen, waitFor } from "@testing-library/react"
import type { ComponentProps, ReactNode } from "react"
import { MemoryRouter } from "react-router"
import { beforeEach, describe, expect, it, vi } from "vitest"

import AccountFavorites from "./favorites"

const navigate = vi.fn()

vi.mock("react-router", async () => {
  const actual = await vi.importActual<typeof import("react-router")>("react-router")
  return { ...actual, useNavigate: () => navigate }
})

vi.mock("../../components/layout/AppShell", () => ({
  AppShell: ({ children }: { children: ReactNode }) => <>{children}</>,
}))

vi.mock("~/components/account/AccountLayout", () => ({
  AccountLayout: ({ children }: { children: ReactNode }) => <>{children}</>,
}))

vi.mock("../../components/media/PlaceImage", () => ({
  PlaceImage: ({ placeName }: { placeName: string }) => <span>{placeName} image</span>,
}))

vi.mock("sonner", () => ({ toast: { info: vi.fn(), error: vi.fn() } }))

const fetchMock = vi.fn<typeof fetch>()
const session = { authenticated: true, user: null, csrfToken: "csrf-token" }

function favorite(id: string, name: string) {
  return { id, placeId: `place-${id}`, place: { name, slug: `place-${id}`, published: true } }
}

function loaderData(items = [favorite("1", "Park Alfa"), favorite("2", "Park Beta")], page = 1, totalItems = items.length, totalPages = 1) {
  return { session, favoritesList: { items, pagination: { page, totalItems, totalPages } } }
}

function renderFavorites(data = loaderData()) {
  const props = { loaderData: data } as ComponentProps<typeof AccountFavorites>
  return render(<MemoryRouter><AccountFavorites {...props} /></MemoryRouter>)
}

function deferredResponse() {
  let resolve!: (response: Response) => void
  const promise = new Promise<Response>((done) => { resolve = done })
  return { promise, resolve }
}

describe("account favorites mutations", () => {
  beforeEach(() => {
    fetchMock.mockReset()
    navigate.mockReset()
    vi.stubGlobal("fetch", fetchMock)
  })

  it("locks duplicate removal, exposes pending state, and updates visible count and items", async () => {
    const request = deferredResponse()
    fetchMock.mockReturnValue(request.promise)
    renderFavorites()

    const remove = screen.getAllByRole("button", { name: "Usuń z ulubionych" })[0]
    fireEvent.click(remove)
    fireEvent.click(remove)

    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(remove).toHaveAttribute("aria-busy", "true")
    expect(screen.getByRole("status")).toHaveTextContent("Usuwanie z ulubionych")

    request.resolve({ ok: true } as Response)
    await waitFor(() => expect(screen.queryByText("Park Alfa")).not.toBeInTheDocument())
    expect(screen.getByText("Park Beta")).toBeInTheDocument()
    expect(screen.getByText(/^1 zapisane miejsce$/)).toBeInTheDocument()
  })

  it("preserves the visible item and total after a failed removal", async () => {
    fetchMock.mockResolvedValue({ ok: false } as Response)
    renderFavorites()

    fireEvent.click(screen.getAllByRole("button", { name: "Usuń z ulubionych" })[0])

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1))
    expect(screen.getByText("Park Alfa")).toBeInTheDocument()
    expect(screen.getByText(/^2 zapisanych miejsc$/)).toBeInTheDocument()
  })

  it("renders the empty state after removing the final item", async () => {
    fetchMock.mockResolvedValue({ ok: true } as Response)
    renderFavorites(loaderData([favorite("1", "Park Alfa")]))

    fireEvent.click(screen.getByRole("button", { name: "Usuń z ulubionych" }))

    expect(await screen.findByText("Tu pojawią się Twoje ulubione")).toBeInTheDocument()
    expect(screen.queryByText("Park Alfa")).not.toBeInTheDocument()
  })

  it("navigates to the previous valid page after removing its final item", async () => {
    fetchMock.mockResolvedValue({ ok: true } as Response)
    renderFavorites(loaderData([favorite("3", "Park Gamma")], 2, 3, 2))

    fireEvent.click(screen.getByRole("button", { name: "Usuń z ulubionych" }))

    await waitFor(() => expect(navigate).toHaveBeenCalledWith("/konto/ulubione?page=1"))
  })

  it("synchronizes visible items and total after loader revalidation", () => {
    const initial = loaderData()
    const { rerender } = renderFavorites(initial)
    const refreshed = loaderData([favorite("2", "Park Beta")], 1, 1, 1)
    const props = { loaderData: refreshed } as ComponentProps<typeof AccountFavorites>

    rerender(<MemoryRouter><AccountFavorites {...props} /></MemoryRouter>)

    expect(screen.queryByText("Park Alfa")).not.toBeInTheDocument()
    expect(screen.getByText(/^1 zapisane miejsce$/)).toBeInTheDocument()
  })
})
