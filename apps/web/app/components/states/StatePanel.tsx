import type { LucideIcon } from "lucide-react"
import { CircleAlert, Compass } from "lucide-react"
import type { ReactNode } from "react"

import { Card, CardContent } from "~/components/ui/card"

interface StatePanelProps {
  title: string
  description: string
  action?: ReactNode
  tone?: "empty" | "error"
  icon?: LucideIcon
}

export function StatePanel({ title, description, action, tone = "empty", icon }: StatePanelProps) {
  const Icon = icon ?? (tone === "error" ? CircleAlert : Compass)
  return (
    <Card role={tone === "error" ? "alert" : undefined} className="border-dashed bg-card/70 py-0 shadow-none">
      <CardContent className="flex flex-col items-center px-6 py-12 text-center">
        <span className="mb-5 flex size-12 items-center justify-center rounded-2xl bg-secondary text-primary">
          <Icon className="size-6" aria-hidden="true" />
        </span>
        <h2 className="text-xl font-bold">{title}</h2>
        <p className="mt-2 max-w-md text-base leading-relaxed text-muted-foreground">{description}</p>
        {action ? <div className="mt-6">{action}</div> : null}
      </CardContent>
    </Card>
  )
}
