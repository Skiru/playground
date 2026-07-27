import * as React from "react"
import { Button } from "~/components/ui/button"

interface CommentFormProps {
  initialBody?: string
  isReply?: boolean
  isEdit?: boolean
  submitting: boolean
  formError: string | null
  onSubmit: (body: string) => void
  onCancel?: () => void
}

export function CommentForm({
  initialBody = "",
  isReply = false,
  isEdit = false,
  submitting,
  formError,
  onSubmit,
  onCancel,
}: CommentFormProps) {
  const [body, setBody] = React.useState(initialBody)

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!body.trim()) return
    onSubmit(body.trim())
  }

  return (
    <form onSubmit={handleSubmit} className="border p-4 rounded-lg bg-muted/30 flex flex-col gap-3">
      <h3 className="font-semibold text-xs">
        {isEdit
          ? "Edytuj swój komentarz"
          : isReply
            ? `Odpowiedź na komentarz`
            : "Napisz komentarz do tego miejsca"}
      </h3>
      {formError && (
        <p className="text-xs text-destructive font-medium bg-destructive/10 p-2 rounded" role="alert">
          {formError}
        </p>
      )}
      <textarea
        rows={3}
        className="w-full border rounded-md p-2 bg-background text-sm focus:ring-1 focus:ring-primary"
        placeholder={isReply ? "Napisz swoją odpowiedź..." : "Zadaj pytanie, podziel się uwagą..."}
        value={body}
        onChange={(e) => setBody(e.target.value)}
        required
        maxLength={3000}
      />
      <div className="flex justify-between items-center gap-4">
        <span className="text-3xs text-muted-foreground">{body.length}/3000 znaków</span>
        <div className="flex gap-2">
          {onCancel && (
            <Button
              type="button"
              size="xs"
              variant="ghost"
              className="text-2xs font-semibold"
              onClick={onCancel}
            >
              Anuluj
            </Button>
          )}
          <Button
            type="submit"
            size="xs"
            className="text-2xs font-semibold"
            disabled={submitting || body.trim().length === 0}
          >
            {submitting ? "Wysyłanie..." : "Wyślij"}
          </Button>
        </div>
      </div>
    </form>
  )
}
