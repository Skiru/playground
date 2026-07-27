import { render, screen } from "@testing-library/react"
import { describe, expect, it } from "vitest"

import { Button } from "./button"

describe("Button semantic colors", () => {
  it("uses the primary semantic pair without an important override", () => {
    render(<Button>Primary action</Button>)

    const button = screen.getByRole("button", { name: "Primary action" })
    expect(button).toHaveClass("bg-primary", "text-primary-foreground")
    expect(button.className).not.toContain("!text-primary-foreground")
    expect(button.className).not.toContain("text-white")
  })

  it("uses the destructive semantic pair", () => {
    render(<Button variant="destructive">Destructive action</Button>)

    const button = screen.getByRole("button", { name: "Destructive action" })
    expect(button).toHaveClass("bg-destructive", "text-destructive-foreground")
    expect(button.className).not.toContain("text-white")
  })
})
