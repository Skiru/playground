import * as React from "react"
import { useSession } from "~/lib/session-context"

interface PlaceState {
  favorite: boolean
  lastVisitedOn: string | null
}

export function usePlaceStates(placeIds: string[]) {
  const { session } = useSession()
  const [states, setStates] = React.useState<Record<string, PlaceState>>({})
  const [isLoading, setIsLoading] = React.useState(false)

  const loadStates = React.useCallback(async (active: boolean) => {
    if (!session.authenticated || placeIds.length === 0) return

    setIsLoading(true)
    try {
      const queryParams = new URLSearchParams()
      placeIds.forEach((id) => queryParams.append("placeIds[]", id))

      const res = await fetch(`/resources/place-state?${queryParams.toString()}`)
      if (res.ok && active) {
        const data = await res.json()
        setStates(data)
      }
    } catch {
      // Ignored
    } finally {
      if (active) setIsLoading(false)
    }
  }, [session.authenticated, placeIds])

  React.useEffect(() => {
    let active = true
    const timer = setTimeout(() => {
      void loadStates(active)
    }, 0)
    return () => {
      active = false
      clearTimeout(timer)
    }
  }, [loadStates])

  const toggleFavorite = React.useCallback(async (placeId: string) => {
    if (!session.authenticated) return { error: "AUTH_REQUIRED" }

    const currentState = states[placeId] || { favorite: false, lastVisitedOn: null }
    const nextFavorite = !currentState.favorite

    // Optimistic update
    setStates((prev) => ({
      ...prev,
      [placeId]: { ...currentState, favorite: nextFavorite },
    }))

    try {
      const endpoint = nextFavorite ? "/resources/favorites" : `/resources/favorites?placeId=${placeId}`
      const method = nextFavorite ? "POST" : "DELETE"

      const res = await fetch(endpoint, {
        method,
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-Token": session.csrfToken || "",
        },
        body: nextFavorite ? JSON.stringify({ placeId }) : undefined,
      })

      if (!res.ok) {
        // Rollback
        setStates((prev) => ({
          ...prev,
          [placeId]: currentState,
        }))
        return { success: false }
      }

      return { success: true }
    } catch {
      // Rollback
      setStates((prev) => ({
        ...prev,
        [placeId]: currentState,
      }))
      return { success: false }
    }
  }, [session.authenticated, session.csrfToken, states])

  const addVisit = React.useCallback(async (placeId: string, visitedOn: string, note: string) => {
    if (!session.authenticated) return { error: "AUTH_REQUIRED" }

    try {
      const res = await fetch("/resources/visits", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-Token": session.csrfToken || "",
        },
        body: JSON.stringify({ placeId, visitedOn, note }),
      })

      if (res.ok) {
        // Re-load states
        void loadStates(true)
        return { success: true }
      } else {
        const data = await res.json().catch(() => ({}))
        return { success: false, error: data.detail || "Validation failed" }
      }
    } catch {
      return { success: false, error: "Network error" }
    }
  }, [session.authenticated, session.csrfToken, loadStates])

  return { states, isLoading, toggleFavorite, addVisit }
}
