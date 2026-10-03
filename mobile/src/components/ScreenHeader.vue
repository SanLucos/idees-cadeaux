<template>
  <header class="screen-header">
    <div class="screen-header__text">
      <div v-if="$slots.kicker" class="screen-header__kicker"><slot name="kicker" /></div>
      <h1 class="ic-screen-title"><slot /></h1>
    </div>
    <div v-if="$slots.end" class="screen-header__end"><slot name="end" /></div>
  </header>
</template>

<style scoped>
.screen-header {
  display: flex;
  /* Large text (spec §9): the actions go below rather than squeeze the title. */
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 12px;
  padding-top: calc(var(--ion-safe-area-top, 0px) + 40px);
  padding-bottom: 12px;
}

.screen-header__text {
  flex: 1 1 auto;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.screen-header__kicker {
  font-size: 0.875rem;
  color: var(--ic-text-secondary);
}

.screen-header__end {
  display: flex;
  gap: 8px;
  align-items: center;
}
</style>
