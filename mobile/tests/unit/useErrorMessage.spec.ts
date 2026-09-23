import { describe, expect, test } from 'vitest'
import { withSetup } from './withSetup'
import { useErrorMessage } from '@/composables/useErrorMessage'
import { ApiError } from '@/services/api'

describe('useErrorMessage', () => {
  test('maps a known backend code to its French message', () => {
    const [{ describe: describeError }] = withSetup(() => useErrorMessage())
    const message = describeError(new ApiError(401, 'auth.invalid_credentials', 'Invalid credentials.'))
    expect(message).toBe('Email ou mot de passe incorrect.')
  })

  test('falls back to a generic message for an unmapped code', () => {
    const [{ describe: describeError }] = withSetup(() => useErrorMessage())
    const message = describeError(new ApiError(500, 'server.internal_error', 'boom'))
    expect(message).toBe('Une erreur est survenue.')
  })

  test('falls back to a generic message for a non-ApiError', () => {
    const [{ describe: describeError }] = withSetup(() => useErrorMessage())
    expect(describeError(new Error('network down'))).toBe('Une erreur est survenue.')
  })

  test('fills placeholders from the problem+json extra members', () => {
    const [{ describe: describeError }] = withSetup(() => useErrorMessage())
    const error = new ApiError(409, 'reservation.already_reserved', 'Already reserved.', { reservedBy: 'Hugo' })
    expect(describeError(error)).toBe('Déjà réservé par Hugo.')
  })
})
