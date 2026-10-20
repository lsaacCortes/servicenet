/**
 * Aplica máscara de CPF no input
 * @param {HTMLInputElement} input 
 */
function mascaraCPF(input) {
    let cpf = input.value;
    cpf = cpf.replace(/\D/g, "");
    cpf = cpf.replace(/(\d{3})(\d)/, "$1.$2");
    cpf = cpf.replace(/(\d{3})(\d)/, "$1.$2");
    cpf = cpf.replace(/(\d{3})(\d{1,2})$/, "$1-$2");
    input.value = cpf;
}
