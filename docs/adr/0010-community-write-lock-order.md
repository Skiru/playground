# Community Write Lock Ordering

Forum post creation locks the `forum_threads` row first, validates the committed thread and category state, then locks an optional parent `forum_posts` row. It inserts the reply and updates `last_activity_at` before committing.

Thread moderation and author thread deletion use the same thread-first order. Post moderation locks only its post row because it does not mutate the thread. This keeps contention scoped to the affected thread or parent row and avoids acquiring a parent-post lock before a thread lock.
