import * as React from "react"

interface PageContainerProps extends React.ComponentProps<"div"> {
  children: React.ReactNode
}

export function PageContainer({ children, className, ...props }: PageContainerProps) {
  return (
    <div
      className={`mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8 ${className || ""}`}
      {...props}
    >
      {children}
    </div>
  )
}
