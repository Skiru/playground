import * as React from "react"
import { Button } from "~/components/ui/button"

export function UserAreaPlaceholder() {
  return (
    <div className="flex items-center gap-2">
      <Button variant="outline" size="sm" className="font-semibold">
        Zaloguj się
      </Button>
    </div>
  )
}
