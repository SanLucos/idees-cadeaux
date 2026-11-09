import { describe, expect, test, vi } from 'vitest'
import { registerServiceWorker } from '@/services/serviceWorker'

class FakeTarget extends EventTarget {}

function setup(controlled: boolean) {
  const container = new FakeTarget() as FakeTarget & { controller: unknown; register: ReturnType<typeof vi.fn> }
  const registration = new FakeTarget() as FakeTarget & { installing: unknown; update: ReturnType<typeof vi.fn> }
  registration.update = vi.fn(() => Promise.resolve())
  container.controller = controlled ? {} : null
  container.register = vi.fn(() => Promise.resolve(registration))
  const reload = vi.fn()
  const onUpdateFound = vi.fn()
  registerServiceWorker({ container: container as never, reload, onUpdateFound })
  return { container, registration, reload, onUpdateFound }
}

describe('web app updates (service worker)', () => {
  test('registers /sw.js', () => {
    expect(setup(true).container.register).toHaveBeenCalledWith('/sw.js')
  })

  test('a new worker taking over reloads the page, once', () => {
    const { container, reload } = setup(true)
    container.dispatchEvent(new Event('controllerchange'))
    container.dispatchEvent(new Event('controllerchange'))
    expect(reload).toHaveBeenCalledTimes(1)
  })

  test('the first install (no controller before) does not reload', () => {
    const { container, reload } = setup(false)
    container.dispatchEvent(new Event('controllerchange'))
    expect(reload).not.toHaveBeenCalled()
  })

  test('after a first install, the next version reloads the page', () => {
    const { container, reload } = setup(false)
    container.dispatchEvent(new Event('controllerchange'))
    container.dispatchEvent(new Event('controllerchange'))
    expect(reload).toHaveBeenCalledTimes(1)
  })

  test('an installed new worker is reported as an update', async () => {
    const { registration, onUpdateFound } = setup(true)
    await Promise.resolve()
    const worker = new FakeTarget() as FakeTarget & { state: string }
    worker.state = 'installing'
    registration.installing = worker
    registration.dispatchEvent(new Event('updatefound'))
    worker.state = 'installed'
    worker.dispatchEvent(new Event('statechange'))
    expect(onUpdateFound).toHaveBeenCalledTimes(1)
  })

  test('coming back to the foreground checks for a new version', async () => {
    const { registration } = setup(true)
    await Promise.resolve()
    document.dispatchEvent(new Event('visibilitychange'))
    expect(registration.update).toHaveBeenCalled()
  })
})
