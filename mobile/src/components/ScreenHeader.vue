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
  align-items: flex-end;
  gap: 12px;
  padding-top: calc(var(--ion-safe-area-top, 0px) + 40px);
  padding-bottom: 12px;
}

.screen-header__text {
  flex-grow: 1;
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}

.screen-header__kicker {
  font-size: 14px;
  color: var(--ic-text-secondary);
}

.screen-header__end {
  display: flex;
  gap: 8px;
  align-items: center;
}
</style>
