import { fireEvent, render, screen, waitFor } from "@testing-library/react"
import type { ComponentProps, ReactNode } from "react"
import { MemoryRouter } from "react-router"
import { beforeEach, describe, expect, it, vi } from "vitest"

import AccountVisits from "./visits"

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

vi.mock("sonner", () => ({ toast: { info: vi.fn(), success: vi.fn(), error: vi.fn() } }))

const fetchMock = vi.fn<typeof fetch>()
const session = { authenticated: true, user: null, csrfToken: "csrf-token" }

function visit(id: string, name: string) {
  return { id, visitedOn: "2026-07-20", note: `Notatka ${id}`, place: { name, slug: `place-${id}`, published: true } }
}

function loaderData(items = [visit("1", "Park Alfa"), visit("2", "Park Beta")], page = 1, totalItems = items.length, totalPages = 1) {
  return { session, visitsList: { items, pagination: { page, totalItems, totalPages } } }
}

function renderVisits(data = loaderData()) {
  const props = { loaderData: data } as ComponentProps<typeof AccountVisits>
  return render(<MemoryRouter><AccountVisits {...props} /></MemoryRouter>)
}

async function openDeleteDialog() {
  fireEvent.click(screen.getAllByRole("button", { name: "Usuń wizytę" })[0])
  return screen.findByRole("button", { name: "Usuń trwale" })
}

function deferredResponse() {
  let resolve!: (response: Response) => void
  const promise = new Promise<Response>((done) => { resolve = done })
  return { promise, resolve }
}

describe("account visit mutations", () => {
  beforeEach(() => {
    fetchMock.mockReset()
    navigate.mockReset()
    vi.stubGlobal("fetch", fetchMock)
  })

  it("locks duplicate deletion, exposes pending state, and updates visible count and items", async () => {
    const request = deferredResponse()
    fetchMock.mockReturnValue(request.promise)
    renderVisits()
    const confirm = await openDeleteDialog()

    fireEvent.click(confirm)
    fireEvent.click(confirm)

    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(confirm).toHaveAttribute("aria-busy", "true")
    expect(screen.getByRole("status")).toHaveTextContent("Usuwanie")

    request.resolve({ ok: true } as Response)
    await waitFor(() => expect(screen.queryByText("Park Alfa")).not.toBeInTheDocument())
    expect(screen.getByText("Park Beta")).toBeInTheDocument()
    expect(screen.getByText(/^1 zapisana wizyta$/)).toBeInTheDocument()
  })

  it("preserves the visible item and total after a failed deletion", async () => {
    fetchMock.mockResolvedValue({ ok: false } as Response)
    renderVisits()
    fireEvent.click(await openDeleteDialog())

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1))
    expect(screen.getByText("Park Alfa")).toBeInTheDocument()
    expect(screen.getByText(/^2 zapisanych wizyt$/)).toBeInTheDocument()
  })

  it("renders the empty state after deleting the final visit", async () => {
    fetchMock.mockResolvedValue({ ok: true } as Response)
    renderVisits(loaderData([visit("1", "Park Alfa")]))
    fireEvent.click(await openDeleteDialog())

    expect(await screen.findByText("Tu pojawi się historia Waszych wizyt")).toBeInTheDocument()
    expect(screen.queryByText("Park Alfa")).not.toBeInTheDocument()
  })

  it("navigates to the previous valid page after deleting its final visit", async () => {
    fetchMock.mockResolvedValue({ ok: true } as Response)
    renderVisits(loaderData([visit("3", "Park Gamma")], 2, 3, 2))
    fireEvent.click(await openDeleteDialog())

    await waitFor(() => expect(navigate).toHaveBeenCalledWith("/konto/odwiedzone?page=1"))
  })

  it("synchronizes visible items and total after loader revalidation", () => {
    const { rerender } = renderVisits()
    const refreshed = loaderData([visit("2", "Park Beta")], 1, 1, 1)
    const props = { loaderData: refreshed } as ComponentProps<typeof AccountVisits>

    rerender(<MemoryRouter><AccountVisits {...props} /></MemoryRouter>)

    expect(screen.queryByText("Park Alfa")).not.toBeInTheDocument()
    expect(screen.getByText(/^1 zapisana wizyta$/)).toBeInTheDocument()
  })
})
