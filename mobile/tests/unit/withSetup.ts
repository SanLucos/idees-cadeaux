import { createApp } from 'vue'
import { i18n } from '@/i18n'

/** Runs a composable inside a minimal component so it can call useI18n() etc. */
export function withSetup<T>(composable: () => T): [T, ReturnType<typeof createApp>] {
  let result!: T
  const app = createApp({
    setup() {
      result = composable()
      return () => null
    },
  })
  app.use(i18n)
  app.mount(document.createElement('div'))

  return [result, app]
}
