import { defineStore } from 'pinia';
import { api } from '../services/api';

type Status = 'checking' | 'ok' | 'error';

interface HealthResponse {
  status: string;
}

export const useApiHealthStore = defineStore('apiHealth', {
  state: () => ({
    status: 'checking' as Status,
  }),
  actions: {
    async check(): Promise<void> {
      this.status = 'checking';
      try {
        const response = await api.get<HealthResponse>('/health', { auth: false });
        this.status = response.status === 'ok' ? 'ok' : 'error';
      } catch {
        this.status = 'error';
      }
    },
  },
});
