import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Badge } from "./Badge.vue"

export const badgeVariants = cva(
  "inline-flex items-center justify-center rounded-full border px-2 py-0.5 text-xs font-medium w-fit whitespace-nowrap shrink-0 [&>svg]:size-3 gap-1 [&>svg]:pointer-events-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive transition-[color,box-shadow] overflow-hidden",
  {
    variants: {
      variant: {
        default:
          "border-transparent bg-primary text-primary-foreground [a&]:hover:bg-primary/90",
        secondary:
          "border-transparent bg-secondary text-secondary-foreground [a&]:hover:bg-secondary/90",
        destructive:
         "border-transparent bg-destructive text-white [a&]:hover:bg-destructive/90 focus-visible:ring-destructive/20 dark:focus-visible:ring-destructive/40 dark:bg-destructive/60",
        outline:
          "text-foreground [a&]:hover:bg-accent [a&]:hover:text-accent-foreground",
        // Status variants. Colour on a badge means state, never configuration:
        // `waiting` for work that has not moved yet, `moved` for work that has.
        // A proxy's mode or processing strategy is a setting and stays neutral,
        // so a hue on screen is always something happening.
        waiting:
          "border-transparent bg-status-waiting text-status-waiting-foreground",
        moved:
          "border-transparent bg-status-moved text-status-moved-foreground",
        // A qualifier on something else — a paused proxy named beside its own
        // events — rather than that row's own state. Carries the waiting hue so
        // the association is obvious, at an outline's weight so it never
        // out-shouts the status column it sits next to.
        waitingOutline: "border-status-waiting text-status-waiting",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  },
)
export type BadgeVariants = VariantProps<typeof badgeVariants>
