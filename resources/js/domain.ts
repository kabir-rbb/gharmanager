import NepaliDate from 'nepali-date-converter';
export function money(paisa:number){return new Intl.NumberFormat('en-NP',{style:'currency',currency:'NPR',maximumFractionDigits:2}).format(paisa/100)}
export function bsDate(ad:string){try{return new NepaliDate(new Date(`${ad}T12:00:00+05:45`)).format('YYYY-MM-DD')}catch{return 'Outside supported BS range'}}
export function fixed(value:string,scale:number):number{if(!/^\d+(\.\d+)?$/.test(value))throw Error('Invalid decimal');const [a,b='']=value.split('.');if(b.length>scale)throw Error('Too many decimal places');const n=Number(a)*10**scale+Number(b.padEnd(scale,'0'));if(!Number.isSafeInteger(n))throw Error('Amount too large');return n}

export function nepalTime(iso:string){return new Intl.DateTimeFormat('en-GB',{timeZone:'Asia/Kathmandu',dateStyle:'medium',timeStyle:'short',hour12:false}).format(new Date(iso))}
