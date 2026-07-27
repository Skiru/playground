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
  const errorId = React.useId()

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!body.trim()) return
    onSubmit(body.trim())
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col gap-3 rounded-[var(--radius-card)] border bg-muted/20 p-4">
      <h3 className="text-base font-bold text-foreground">
        {isEdit
          ? "Edytuj swój komentarz"
          : isReply
            ? `Odpowiedź na komentarz`
            : "Napisz komentarz do tego miejsca"}
      </h3>
      {formError && (
        <p id={errorId} className="rounded bg-destructive/10 p-2 text-sm font-medium text-destructive" role="alert">
          {formError}
        </p>
      )}
      <label htmlFor={`comment-${errorId}`} className="text-sm font-semibold text-foreground">Treść komentarza</label>
      <textarea
        id={`comment-${errorId}`}
        rows={3}
        className="w-full rounded-[var(--radius-control)] border border-input bg-background p-3 text-base leading-relaxed text-foreground placeholder:text-muted-foreground focus-visible:border-primary"
        placeholder={isReply ? "Napisz swoją odpowiedź..." : "Zadaj pytanie, podziel się uwagą..."}
        value={body}
        onChange={(e) => setBody(e.target.value)}
        required
        maxLength={3000}
        aria-describedby={formError ? errorId : undefined}
        aria-invalid={Boolean(formError)}
        autoFocus
      />
      <div className="flex justify-between items-center gap-4">
        <span className="text-sm text-muted-foreground" aria-live="polite">{body.length}/3000 znaków</span>
        <div className="flex gap-2">
          {onCancel && (
            <Button
              type="button"
              size="sm"
              variant="ghost"
              className="font-semibold"
              onClick={onCancel}
            >
              Anuluj
            </Button>
          )}
          <Button
            type="submit"
            size="sm"
            className="font-semibold"
            disabled={submitting || body.trim().length === 0}
          >
            {submitting ? "Wysyłanie..." : "Wyślij"}
          </Button>
        </div>
      </div>
    </form>
  )
}
