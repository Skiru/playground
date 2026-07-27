import * as React from "react"

interface PageContainerProps extends React.ComponentProps<"div"> {
  children: React.ReactNode
}

export function PageContainer({ children, className, ...props }: PageContainerProps) {
  return (
    <div
      className={`mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 sm:py-10 lg:px-8 ${className || ""}`}
      {...props}
    >
      {children}
    </div>
  )
}
