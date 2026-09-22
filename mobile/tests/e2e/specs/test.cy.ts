describe('Home', () => {
  it('shows the app name', () => {
    cy.visit('/')
    cy.contains('ion-title', 'Idées Cadeaux')
  })
})
