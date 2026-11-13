const {test}=require('node:test');
const assert=require('node:assert/strict');
const init=require('./entity-nif-warning.js');
function fixture(edit=false) {
    const listeners={},attrs={};
    const warning={style:{},setAttribute(){}};
    const field={id:'fields_222',value:'',dataset:{},getAttribute:key=>attrs[key],
        setAttribute:(key,value)=>attrs[key]=value,insertAdjacentElement(){},
        addEventListener:(key,handler)=>listeners[key]=handler,
        ownerDocument:{createElement:()=>warning}};
    const form={action:'https://example.test/index.php?module=items/&action=save'+(edit?'&id=10':''),
        isConnected:true,querySelector:()=>field};
    const failures=[];
    let timer;
    const environment={location:{href:'https://example.test/index.php'},clearTimeout(){timer=undefined;},
        setTimeout(handler){timer=handler;return 1;},console:{error:()=>failures.push(true)}};
    return {field,form,warning,listeners,environment,failures,runTimer:()=>timer()};
}
test('duplicate NIF is advisory, never adds validation or submission interception',async()=>{
    const f=fixture(),calls=[];
    init(f.form,async(url,value)=>{calls.push({url,value});return 'Duplicate';},f.environment);
    f.field.value=' 123456789 ';
    f.listeners.input();
    await f.runTimer();
    assert.equal(f.warning.hidden,false);
    assert.match(f.warning.textContent,/Pode continuar e guardar/);
    assert.equal(calls[0].value,'123456789');
    assert.equal(new URL(calls[0].url).searchParams.get('path'),'26');
    assert.deepEqual(Object.keys(f.listeners).sort(),['blur','input']);
    f.field.value='';
    await f.listeners.blur();
    assert.equal(f.warning.hidden,true);
    assert.equal(calls.length,1);
});
test('unique NIF, edits and repeated initialization do not display warnings',async()=>{
    const f=fixture();
    init(f.form,async()=>true,f.environment);
    init(f.form,async()=>{throw Error('Should not initialize twice');},f.environment);
    f.field.value='123456789';await f.listeners.blur();
    assert.equal(f.warning.hidden,true);
    const edit=fixture(true);
    init(edit.form,async()=>false,edit.environment);
    assert.deepEqual(edit.listeners,{});
});
test('stale requests cannot show an obsolete NIF warning',async()=>{
    const f=fixture(),pending=[];
    init(f.form,()=>new Promise(resolve=>pending.push(resolve)),f.environment);
    f.field.value='123456789';const first=f.listeners.blur();
    f.field.value='987654321';f.listeners.input();const second=f.listeners.blur();
    pending[1](true);await second;pending[0]('Duplicate');await first;
    assert.equal(f.warning.hidden,true);
});
test('failed or malformed duplicate checks display an explicit nonblocking error',async()=>{
    for(const request of [async()=>{throw Error('Network');},async()=>({invalid:true})]) {
        const f=fixture();init(f.form,request,f.environment);
        f.field.value='123456789';await f.listeners.blur();
        assert.equal(f.warning.hidden,false);
        assert.match(f.warning.textContent,/Não foi possível verificar/);
        assert.equal(f.failures.length,1);
    }
});
