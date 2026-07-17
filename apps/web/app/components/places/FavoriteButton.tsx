import * as React from "react"
import { Heart } from "lucide-react"
import { useLoginRequiredAction } from "~/features/auth/LoginRequiredActionContext"
import { usePlaceStates } from "~/hooks/use-place-state"
import { Button } from "~/components/ui/button"
import { toast } from "sonner"

export function FavoriteButton({ placeId }: { placeId: string }) {
  const { requireLogin } = useLoginRequiredAction()
  const { states, toggleFavorite } = usePlaceStates([placeId])

  const isFav = states[placeId]?.favorite ?? false

  const triggerToggle = React.useCallback(async () => {
    const res = await toggleFavorite(placeId)
    if (res && !res.success) {
      toast.error("Wystąpił problem przy aktualizacji ulubionych.")
    } else {
      toast.success(isFav ? "Usunięto z ulubionych." : "Dodano do ulubionych!")
    }
  }, [toggleFavorite, placeId, isFav])

  return (
    <Button
      variant="ghost"
      size="icon"
      className={`h-9 w-9 rounded-full ${isFav ? "text-accent bg-accent/5 hover:bg-accent/10" : "text-muted-foreground hover:bg-muted"}`}
      aria-pressed={isFav}
      onClick={(e) => {
        e.preventDefault()
        e.stopPropagation()
        requireLogin(() => {
          void triggerToggle()
        })
      }}
      title={isFav ? "Usuń z ulubionych" : "Dodaj do ulubionych"}
    >
      <Heart className={`h-5 w-5 ${isFav ? "fill-current" : ""}`} />
      <span className="sr-only">Dodaj do ulubionych</span>
    </Button>
  )
}
