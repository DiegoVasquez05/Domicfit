/*
 * Modo demostración (solo en la vista previa en línea).
 * Reemplaza las llamadas al servidor (api/*.php) por respuestas simuladas que se guardan
 * en este navegador. No se cobra nada ni se guardan reservas reales.
 */
(function () {
  "use strict";

  const CLAVE = "domicfit-demo-pedidos";
  const ANTICIPACION_HORAS = 12;
  const DIAS_MAXIMOS = 30;
  const DURACION = 60;
  const AGENDA = {
    sede: { margen: 0, horarios: { 1: ["10:00", "11:00", "20:00", "21:00", "22:00"], 2: ["10:00", "11:00", "20:00", "21:00", "22:00"], 3: ["10:00", "11:00", "20:00", "21:00", "22:00"], 4: ["10:00", "11:00", "20:00", "21:00", "22:00"], 5: ["10:00", "11:00", "20:00", "21:00", "22:00"] } },
    domicilio: { margen: 30, rangos: { 1: ["06:30", "21:00"], 2: ["06:30", "21:00"], 3: ["06:30", "21:00"], 4: ["06:30", "21:00"], 5: ["06:30", "21:00"], 6: ["07:00", "13:00"] } },
  };
  const DIAS = ["domingo", "lunes", "martes", "miércoles", "jueves", "viernes", "sábado"];
  const MESES = ["enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto", "septiembre", "octubre", "noviembre", "diciembre"];

  /* ---------- Utilidades ---------- */
  const pad = (n) => String(n).padStart(2, "0");
  const fechaTexto = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  const aMinutos = (h) => { const [a, b] = h.split(":").map(Number); return a * 60 + b; };
  const deMinutos = (m) => `${pad(Math.floor(m / 60))}:${pad(m % 60)}`;
  const diaIso = (d) => (d.getDay() === 0 ? 7 : d.getDay());
  const legible = (fecha, hora) => {
    const [a, m, d] = fecha.split("-").map(Number);
    const f = new Date(a, m - 1, d);
    return `${DIAS[f.getDay()]} ${d} de ${MESES[m - 1]} a las ${hora}`;
  };
  const leerPedidos = () => { try { return JSON.parse(localStorage.getItem(CLAVE) || "{}"); } catch (e) { return {}; } };
  const guardarPedidos = (p) => { try { localStorage.setItem(CLAVE, JSON.stringify(p)); } catch (e) { /* sin almacenamiento */ } };
  const json = (datos, estado = 200) => Promise.resolve(new Response(JSON.stringify(datos), { status: estado, headers: { "Content-Type": "application/json" } }));

  // Algunas horas aparecen ocupadas para que la agenda se vea realista (siempre las mismas para cada día).
  function ocupadaDeEjemplo(fecha, hora) {
    let h = 0;
    for (const c of fecha + hora) h = (h * 31 + c.charCodeAt(0)) >>> 0;
    return h % 5 === 0;
  }

  function horasDelDia(modalidad, fecha) {
    const [a, m, d] = fecha.split("-").map(Number);
    const dia = diaIso(new Date(a, m - 1, d));
    const ag = AGENDA[modalidad];
    if (ag.horarios) return ag.horarios[dia] || [];
    const rango = ag.rangos[dia];
    if (!rango) return [];
    const horas = [];
    for (let t = aMinutos(rango[0]); t <= aMinutos(rango[1]); t += 30) horas.push(deMinutos(t));
    return horas;
  }

  function disponibilidad(modalidad, fecha) {
    const ahora = new Date();
    const minimo = new Date(ahora.getTime() + ANTICIPACION_HORAS * 3600e3);
    const maximo = new Date(ahora); maximo.setDate(maximo.getDate() + DIAS_MAXIMOS); maximo.setHours(23, 59);
    const margen = AGENDA[modalidad].margen;
    const propios = Object.values(leerPedidos()).filter((p) => p.modalidad === modalidad && p.fecha === fecha && p.estado !== "cancelado");
    return horasDelDia(modalidad, fecha).map((hora) => {
      const [a, m, d] = fecha.split("-").map(Number);
      const inicio = new Date(a, m - 1, d, ...hora.split(":").map(Number));
      let libre = inicio >= minimo && inicio <= maximo && !ocupadaDeEjemplo(fecha, hora);
      if (libre) {
        const t = aMinutos(hora);
        libre = !propios.some((p) => {
          const o = aMinutos(p.hora);
          return o < t + DURACION + margen && t - margen < o + DURACION;
        });
      }
      return { hora, libre };
    });
  }

  /* ---------- API simulada ---------- */
  async function crearPedido(cuerpo) {
    const resp = await fetch("data/productos.json");
    const cat = Object.fromEntries((await resp.json()).productos.map((p) => [p.id, p]));
    const items = [];
    let total = 0;
    for (const i of cuerpo.items || []) {
      const p = cat[i.id];
      if (!p || p.modalidad !== cuerpo.modalidad) return json({ ok: false, error: "Uno de los productos del carrito no es válido." }, 400);
      const cantidad = p.tipo === "prueba" ? 1 : Math.max(1, Math.min(5, i.cantidad | 0));
      items.push({ id: p.id, nombre: p.nombre, precio: p.precio, cantidad });
      total += p.precio * cantidad;
    }
    if (!items.length) return json({ ok: false, error: "Tu carrito está vacío." }, 400);
    const libre = disponibilidad(cuerpo.modalidad, cuerpo.fecha).find((h) => h.hora === cuerpo.hora)?.libre;
    if (!libre) return json({ ok: false, error: "Ese horario ya no está disponible. Elige otro, por favor." }, 409);

    const ref = Array.from(crypto.getRandomValues(new Uint8Array(8)), (b) => b.toString(16).padStart(2, "0")).join("").toUpperCase();
    const metodo = total === 0 ? "gratis" : cuerpo.metodo;
    const estado = metodo === "gratis" ? "reservado" : metodo === "transferencia" ? "pendiente_transferencia" : "pagado";
    const pedidos = leerPedidos();
    pedidos[ref] = { ref, estado, metodo, total, items, modalidad: cuerpo.modalidad, fecha: cuerpo.fecha, hora: cuerpo.hora, nombre: (cuerpo.cliente?.nombre || "").trim().split(" ")[0] };
    guardarPedidos(pedidos);
    return json({ ok: true, ref, redirigir: `pago.html?ref=${ref}&demo=1` });
  }

  function estadoPedido(ref) {
    const p = leerPedidos()[ref];
    if (!p) return json({ ok: false, error: "Pedido no encontrado." }, 404);
    const r = { ok: true, ...p, inicio: `${p.fecha} ${p.hora}`, inicio_texto: legible(p.fecha, p.hora), whatsapp: "56986558409" };
    if (p.estado === "pendiente_transferencia") {
      const manana = new Date(Date.now() + 24 * 3600e3);
      r.transferencia = { titular: "Datos de ejemplo", rut: "—", banco: "—", tipo: "—", numero: "—", correo: "domicfit@gmail.com" };
      r.expira = legible(fechaTexto(manana), `${pad(manana.getHours())}:${pad(manana.getMinutes())}`);
    }
    return json(r);
  }

  const fetchOriginal = window.fetch.bind(window);
  window.fetch = function (entrada, opciones = {}) {
    const url = new URL(typeof entrada === "string" ? entrada : entrada.url, location.href);
    if (!url.pathname.includes("/api/")) return fetchOriginal(entrada, opciones);
    const q = url.searchParams;
    const ruta = url.pathname.split("/api/")[1];
    if (ruta === "opciones.php") {
      return json({ ok: true, hoy: fechaTexto(new Date()), dias_maximos: DIAS_MAXIMOS, dias_con_atencion: { sede: [1, 2, 3, 4, 5], domicilio: [1, 2, 3, 4, 5, 6] }, transferencia: true, horas_transferencia: 24, minutos_pago_online: 30 });
    }
    if (ruta === "disponibilidad.php") return json({ ok: true, horas: disponibilidad(q.get("modalidad"), q.get("fecha")) });
    if (ruta === "crear-pedido.php") return crearPedido(JSON.parse(opciones.body || "{}"));
    if (ruta === "estado-pedido.php") return estadoPedido((q.get("ref") || "").toUpperCase());
    return json({ ok: false, error: "No disponible en la vista previa." }, 404);
  };

  /* ---------- Aviso visible ---------- */
  document.addEventListener("DOMContentLoaded", () => {
    const aviso = document.createElement("div");
    aviso.className = "aviso-demo";
    aviso.setAttribute("role", "note");
    aviso.innerHTML = "<strong>Vista previa.</strong> Puedes probar el carrito y la agenda: no se cobra nada ni se guardan reservas reales.";
    document.body.prepend(aviso);

    // En el paso de pago se aclara que Mercado Pago está simulado.
    const opcionMp = document.querySelector('input[name="metodo"][value="mercadopago"]');
    if (opcionMp) {
      const texto = opcionMp.closest("label").querySelector("small");
      if (texto) texto.textContent = "En la vista previa el pago se simula como aprobado.";
    }
  });
})();
