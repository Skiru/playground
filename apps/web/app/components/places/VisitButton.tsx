import * as React from "react"
import { Compass, Check, Calendar, MessageSquare } from "lucide-react"
import { useLoginRequiredAction } from "~/features/auth/LoginRequiredActionContext"
import { usePlaceStates } from "~/hooks/use-place-state"
import { Button } from "~/components/ui/button"
import { Input } from "~/components/ui/input"
import { Label } from "~/components/ui/label"
import { toast } from "sonner"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from "~/components/ui/dialog"

export function VisitButton({ placeId }: { placeId: string }) {
  const { requireLogin } = useLoginRequiredAction()
  const { states, addVisit } = usePlaceStates([placeId])
  const [isVisitOpen, setIsVisitOpen] = React.useState(false)
  const [isLoading, setIsLoading] = React.useState(false)
  const [errorMsg, setErrorMsg] = React.useState<string | null>(null)
  
  const [visitedOn, setVisitedOn] = React.useState(() => new Date().toISOString().split("T")[0])
  const [note, setNote] = React.useState("")

  const lastVisited = states[placeId]?.lastVisitedOn

  const handleButtonClick = () => {
    requireLogin(() => {
      setIsVisitOpen(true)
    })
  }

  const handleSaveVisit = async () => {
    if (!visitedOn) {
      toast.error("Wybierz datę wizyty.")
      return
    }

    if (note && note.length > 1000) {
      toast.error("Notatka nie może przekraczać 1000 znaków.")
      return
    }

    setIsLoading(true)
    setErrorMsg(null)
    const res = await addVisit(placeId, visitedOn, note)
    setIsLoading(false)

    if (res.success) {
      setIsVisitOpen(false)
      setNote("")
      toast.success("Wizyta została zapisana w historii!")
    } else {
      setErrorMsg(res.error || "Nie udało się zapisać wizyty.")
    }
  }

  return (
    <>
      <Button
        variant={lastVisited ? "secondary" : "default"}
        size="sm"
        className="font-bold text-xs gap-1.5"
        onClick={handleButtonClick}
      >
        {lastVisited ? (
          <>
            <Check className="size-4 text-primary" />
            Byliśmy tutaj ({lastVisited})
          </>
        ) : (
          <>
            <Compass className="size-4" />
            Byliśmy tutaj
          </>
        )}
      </Button>

      {/* Visit Dialog */}
      <Dialog open={isVisitOpen} onOpenChange={setIsVisitOpen}>
        <DialogContent className="sm:max-w-[420px] p-6">
          <DialogHeader>
            <DialogTitle className="font-serif text-xl font-bold">Zapisz wizytę</DialogTitle>
            <DialogDescription className="text-xs text-muted-foreground">
              Dodaj to miejsce do historii odwiedzin Twojej rodziny.
            </DialogDescription>
          </DialogHeader>

          <div className="grid gap-4 py-4">
            <div className="grid gap-1.5">
              <Label htmlFor="visitDate" className="text-xs font-bold text-muted-foreground uppercase font-mono flex items-center gap-1">
                <Calendar className="size-3.5 text-primary" />
                Data wizyty
              </Label>
              <Input
                id="visitDate"
                type="date"
                value={visitedOn}
                onChange={(e) => setVisitedOn(e.target.value)}
                max={new Date().toISOString().split("T")[0]}
              />
            </div>
            <div className="grid gap-1.5">
              <Label htmlFor="visitNote" className="text-xs font-bold text-muted-foreground uppercase font-mono flex items-center gap-1">
                <MessageSquare className="size-3.5 text-primary" />
                Notatki z pobytu (opcjonalnie)
              </Label>
              <textarea
                id="visitNote"
                rows={4}
                value={note}
                onChange={(e) => setNote(e.target.value)}
                placeholder="Napisz, co najbardziej podobało się dzieciom, na co uważać..."
                className="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-colors placeholder:text-muted-foreground/70 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
              />
              <span className="text-3xs text-right text-muted-foreground">
                Maksymalnie 1000 znaków
              </span>
            </div>
          </div>

          <div className="flex justify-end gap-2 border-t pt-4">
            <Button variant="outline" size="sm" className="font-bold" onClick={() => setIsVisitOpen(false)}>
              Anuluj
            </Button>
            <Button
              size="sm"
              className="font-bold bg-primary hover:bg-primary/95 text-white"
              onClick={handleSaveVisit}
              disabled={isLoading}
            >
              Zapisz wizytę
            </Button>
          </div>
          {errorMsg && (
            <p className="text-xs font-semibold text-destructive mt-2 text-center">{errorMsg}</p>
          )}
        </DialogContent>
      </Dialog>
    </>
  )
}
