import * as React from "react"
import { Button } from "~/components/ui/button"

interface ReviewFormProps {
  initialRating?: number
  initialBody?: string
  initialVisitedOn?: string
  submitting: boolean
  formError: string | null
  onSubmit: (data: { rating: number; body: string; visitedOn: string | null }) => void
  onCancel: () => void
}

export function ReviewForm({
  initialRating = 5,
  initialBody = "",
  initialVisitedOn = "",
  submitting,
  formError,
  onSubmit,
  onCancel,
}: ReviewFormProps) {
  const [rating, setRating] = React.useState(initialRating)
  const [body, setBody] = React.useState(initialBody)
  const [visitedOn, setVisitedOn] = React.useState(initialVisitedOn)

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    onSubmit({
      rating,
      body,
      visitedOn: visitedOn || null,
    })
  }

  return (
    <form onSubmit={handleSubmit} className="border p-4 rounded-lg bg-muted/30 flex flex-col gap-4">
      <h3 className="font-semibold text-sm">
        {initialBody ? "Edytuj swoją opinię" : "Napisz nową opinię"}
      </h3>
      {formError && (
        <p className="text-xs text-destructive font-medium bg-destructive/10 p-2 rounded" role="alert">
          {formError}
        </p>
      )}

      <div className="flex flex-col gap-1.5">
        <label className="text-xs font-semibold text-muted-foreground">
          Twoja ocena (1-5 gwiazdek) *
        </label>
        <div className="flex gap-1.5">
          {[1, 2, 3, 4, 5].map((val) => (
            <button
              key={val}
              type="button"
              className={`text-xl focus:outline-none focus:ring-1 focus:ring-primary ${
                val <= rating ? "text-amber-500" : "text-muted-foreground/30"
              }`}
              onClick={() => setRating(val)}
            >
              ★
            </button>
          ))}
        </div>
      </div>

      <div className="flex flex-col gap-1.5">
        <label htmlFor="review-form-body" className="text-xs font-semibold text-muted-foreground">
          Treść opinii * (minimum 20 znaków)
        </label>
        <textarea
          id="review-form-body"
          rows={4}
          className="w-full border rounded-md p-2 bg-background text-sm focus:ring-1 focus:ring-primary"
          placeholder="Co najbardziej podobało się Twoim dzieciom? Jak oceniasz obsługę i czystość?"
          value={body}
          onChange={(e) => setBody(e.target.value)}
          required
        />
        <p className="text-3xs text-muted-foreground text-right">
          {body.length}/5000 znaków (min. 20)
        </p>
      </div>

      <div className="flex flex-col gap-1.5">
        <label htmlFor="review-form-visited-on" className="text-xs font-semibold text-muted-foreground">
          Kiedy tam byłeś? (Opcjonalnie)
        </label>
        <input
          id="review-form-visited-on"
          type="date"
          className="border rounded-md p-2 bg-background text-sm max-w-xs"
          value={visitedOn}
          onChange={(e) => setVisitedOn(e.target.value)}
        />
      </div>

      <div className="flex gap-3 justify-end mt-2">
        <Button
          type="button"
          size="sm"
          variant="ghost"
          className="text-xs font-semibold"
          onClick={onCancel}
        >
          Anuluj
        </Button>
        <Button
          type="submit"
          size="sm"
          className="text-xs font-semibold"
          disabled={submitting || body.trim().length < 20}
        >
          {submitting ? "Zapisywanie..." : "Zapisz opinię"}
        </Button>
      </div>
    </form>
  )
}
