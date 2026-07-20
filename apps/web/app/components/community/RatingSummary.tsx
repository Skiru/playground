import * as React from "react"

interface Summary {
  averageRating: number
  totalReviews: number
  histogram: Record<number, number>
}

interface RatingSummaryProps {
  summary: Summary
}

export function RatingSummary({ summary }: RatingSummaryProps) {
  const renderStars = (rating: number) => {
    return (
      <div className="flex text-amber-500 font-bold" aria-label={`Ocena: ${rating} na 5`}>
        {"★".repeat(rating)}{"☆".repeat(5 - rating)}
      </div>
    )
  }

  if (summary.totalReviews === 0) {
    return (
      <div className="text-center p-6 border border-dashed rounded-lg">
        <p className="text-sm text-muted-foreground italic">
          Brak opinii dla tego miejsca. Bądź pierwszym, który doda opinię!
        </p>
      </div>
    )
  }

  return (
    <div className="grid grid-cols-1 md:grid-cols-[1fr_2fr] gap-6 p-4 rounded-xl border bg-muted/10">
      <div className="flex flex-col items-center justify-center gap-1.5 border-r md:pr-6">
        <span className="font-serif text-4xl sm:text-5xl font-semibold text-foreground">
          {summary.averageRating.toFixed(1)}
        </span>
        {renderStars(Math.round(summary.averageRating))}
        <span className="text-2xs text-muted-foreground">
          na podstawie {summary.totalReviews} {summary.totalReviews === 1 ? "opinii" : "opinii"}
        </span>
      </div>
      <div className="flex flex-col gap-2 justify-center">
        {[5, 4, 3, 2, 1].map((stars) => {
          const count = summary.histogram[stars] || 0
          const percentage = summary.totalReviews > 0 ? (count / summary.totalReviews) * 100 : 0
          return (
            <div key={stars} className="flex items-center gap-2 text-xs text-muted-foreground">
              <span className="w-3 text-right">{stars}</span>
              <span className="text-amber-500">★</span>
              <div className="flex-1 h-2 bg-muted rounded-full overflow-hidden">
                <div
                  className="h-full bg-amber-500 rounded-full"
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
