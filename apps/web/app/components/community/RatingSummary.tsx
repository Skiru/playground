import { Star } from "lucide-react"

interface Summary {
  averageRating: number
  totalReviews: number
  histogram: Record<number, number>
}

interface RatingSummaryProps {
  summary: Summary
}

export function RatingSummary({ summary }: RatingSummaryProps) {
  return (
    <div id="rating-summary-stats" className="grid grid-cols-1 gap-6 rounded-[var(--radius-card)] border bg-muted/20 p-5 md:grid-cols-[1fr_2fr]">
      <div className="flex flex-col items-center justify-center gap-2 md:border-r md:pr-6">
        <span className="text-3xl font-extrabold text-foreground sm:text-4xl">
          {summary.totalReviews > 0 ? summary.averageRating.toFixed(1) : "Brak ocen"}
        </span>
        <div role="img" className="flex gap-1 text-accent" aria-label={summary.totalReviews > 0 ? `Ocena ${summary.averageRating.toFixed(1)} na 5` : "Brak ocen"}>
          {[1, 2, 3, 4, 5].map((value) => <Star key={value} className={`size-4 ${value <= Math.round(summary.averageRating) ? "fill-current" : ""}`} aria-hidden="true" />)}
        </div>
        <span className="text-sm text-muted-foreground">
          na podstawie <span data-testid="total-reviews-count" id="total-reviews-number">{summary.totalReviews}</span> opinii
        </span>
      </div>
      <div className="flex flex-col gap-2 justify-center">
        {[5, 4, 3, 2, 1].map((stars) => {
          const count = summary.histogram[stars] || 0
          const percentage = summary.totalReviews > 0 ? (count / summary.totalReviews) * 100 : 0
          return (
            <div key={stars} className="flex items-center gap-2 text-sm text-muted-foreground">
              <span className="w-3 text-right">{stars}</span>
              <Star className="size-3.5 fill-accent text-accent" aria-hidden="true" />
              <div className="flex-1 h-2 bg-muted rounded-full overflow-hidden">
                <div
                    className="h-full rounded-full bg-accent"
                  style={{ width: `${percentage}%` }}
                />
              </div>
              <span className="w-8 text-right font-mono">{count}</span>
            </div>
          )
        })}
      </div>
    </div>
  )
}
